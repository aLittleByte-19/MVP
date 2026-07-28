# Communication Pipeline Runbook

## Normal Flow

1. SPA posts a prompt to `POST /api/v1/communications`.
2. `GenerateCommunicationRequest` validates prompt, tone and style.
3. The controller stores the communication with `generation_status=pending` and returns **202** with a relative `streamUrl`.
4. `CommunicationWorkflowService::start()` starts the Step Functions execution and stores execution metadata on `communications`.
5. Step Functions sends callback-token tasks to the communications SQS queue.
6. `php artisan mvp:workflow:consume --queue=communications` receives each message, executes the task through `CommunicationWorkflowTaskHandler`, and calls `SendTaskSuccess` or `SendTaskFailure`.
7. `communication.generate_text` calls Bedrock and stores `generated_title`, `generated_body` and `image_prompt`. The text model also writes the visual direction for the cover, having the generated text in front of it, so the artwork follows the actual communication rather than the raw operator prompt. A failure here fails the execution: the communication is the text.
8. `communication.generate_cover` sends `image_prompt` to the image model and stores the result on the cover disk (`MVP_COMMUNICATION_COVER_DISK`, the emulated S3 by default) under `MVP_COMMUNICATION_COVER_PREFIX`. When the text model returned no visual direction, a generic corporate subject is used. A failure here is recorded on `cover_status`/`cover_error` and the task still succeeds: a missing cover degrades the communication, it does not invalidate it.
9. `communication.finalize` marks `generation_status=completed`, closes a cover left pending by the ASL degraded branch, and records metrics.
10. The SPA follows `GET /api/v1/communications/{communication}/stream` and receives `progress`, `text`, `cover`, `done` and `error` events. The text arrives roughly ten seconds before the cover.
11. Once `generation_status=completed`, the SPA exposes the final laid-out document through `GET /api/v1/communications/{communication}/preview` (inline) and `GET .../export` (attachment). Both are gated on a completed, non-discarded communication and answer **422** otherwise. See "Final PDF" below.

## Required Runtime Configuration

| Variable | Required for | Notes |
| --- | --- | --- |
| `COMMUNICATION_PIPELINE_STATE_MACHINE_ARN` | API workflow start | Created by LocalStack Terraform in local runs. |
| `COMMUNICATION_PIPELINE_TASK_QUEUE_URL` | API and worker | SQS callback-token queue URL, separate from the document one. |
| `COMMUNICATION_PIPELINE_DLQ_URL` | DLQ diagnostics | Used by `mvp:dlq:list --queue=communications`. |
| `MVP_COMMUNICATION_COVER_DISK` | Cover storage | Defaults to the emulated S3 (`s3`) and stays there even when documents move to `real_s3` for Textract: covers are generated assets, not HR documents. |
| `MVP_COMMUNICATION_COVER_PREFIX` | Cover storage | Object key prefix, defaults to `communications/covers`. |
| `MVP_COMMUNICATION_PDF_DISK` | Final PDF cache | Disk holding the materialized PDFs. Falls back to `MVP_COMMUNICATION_COVER_DISK`, then to `FILESYSTEM_DISK`: another generated asset, same reasoning as covers. |
| `MVP_COMMUNICATION_PDF_PREFIX` | Final PDF cache | Object key prefix, defaults to `communications/exports`. Kept separate from covers so the two asset families can be purged independently. |
| `MVP_COMMUNICATION_TIMEOUT_SECONDS` | Stuck detection | Age after which a processing generation is reported as stuck. |
| `BEDROCK_MODEL_ID` | Text generation | Real Bedrock access must be granted externally. |
| `BEDROCK_IMAGE_MODEL_ID` | Cover generation | Defaults to `stability.sd3-5-large-v1:0`. The request payload is derived from the configured model family (Stability SD3/Core, Stability XL, Nova Canvas). Without a reachable model every cover degrades with an explicit warning. |
| `BEDROCK_IMAGE_REGION` | Cover generation | Region serving the image model (defaults to `us-west-2`), usually different from the text model one. The cover uses a dedicated Bedrock client; leave empty to reuse `BEDROCK_REGION`. |

The two pipeline variables are part of `RuntimeConfigurationLoader::REQUIRED_KEYS`: after pulling a change that adds them, run `make refresh-runtime` so SSM parameters are rewritten and the runtime cache is rebuilt. `CommunicationWorkflowService::start()` also fails fast with an actionable message when either value is missing.

## Manual Smoke

```bash
make setup
curl --insecure https://localhost:8443/health
```

Generate a communication from the SPA, then watch:

```bash
make logs
docker compose exec app php artisan mvp:dlq:list --queue=communications
```

Worker logs are also available in Grafana (Loki): see the `communication-pipeline` dashboard or query `{project="mvp", service="queue-communications"}` in the `Logs and Errors` dashboard.

## Scaling Workers

```bash
make workers WORKERS=2   # scales both queue and queue-communications
```

Multiple workers are safe: each Step Functions callback token is tracked in `workflow_tasks` (`task_token_hash` unique) and claimed atomically, so a duplicate SQS delivery is consumed without re-running the business logic (`mvp_sqs_messages_duplicate_total` counts these). The SQS `visibility_timeout_seconds` (900s, Terraform) exceeds the longest ASL task timeout (300s), so an in-flight message never becomes visible to a second worker while still being processed. Workers send `SendTaskHeartbeat` between image generation attempts; a stale `running` task (dead worker) is re-claimable after `MVP_WORKFLOW_CLAIM_TTL_SECONDS` (default 900s).

The communications queue is separate from the documents queue: a slow image generation cannot consume the consumers of the document pipeline, and each domain has its own DLQ and backlog signals.

## Degraded Cover

A degraded cover is an expected outcome, not an incident: the communication stays valid and usable, and the SPA shows the reason under the preview.

| `cover_error` reason | Metric label | Cause |
| --- | --- | --- |
| Image model not configured | `model_not_configured` | `BEDROCK_IMAGE_MODEL_ID` is empty. |
| Account has no access to the model | `model_access_denied` | Model access not granted in the AWS account. |
| Legacy or inactive model | `model_not_available` | The configured image model is no longer served. |
| Invalid credentials | `invalid_credentials` | `AWS_REAL_*` expired or wrong. |
| Blocked by safety controls | `content_filter` | The model refused prompt or output. |
| Generation interrupted | `timeout` | The ASL degraded branch closed a cover left pending. |

Inspect the most frequent reasons:

```sql
SELECT cover_status, cover_error, count(*)
FROM communications
WHERE cover_status = 'failed'
GROUP BY cover_status, cover_error
ORDER BY count(*) DESC;
```

`CommunicationCoverGenerationDegraded` fires only above three degradations in thirty minutes, so a single refused prompt does not page anyone.

## Final PDF

`CommunicationPdfService` lays out title, body and cover into the A4 document served by both `preview` and `export`. Every page carries the `Creato da AI Assistant` transparency marker and the NEXUM footer with page numbers, stamped through the dompdf canvas API because dompdf does not render CSS3 margin boxes.

Rendering is the most expensive operation in the API and its result is deterministic, so the PDF is **materialized once** on the storage disk and re-read afterwards:

- the object key is a fingerprint (SHA-1) of title, body, cover status, cover path and cover MIME, stored at `{MVP_COMMUNICATION_PDF_PREFIX}/{id}/{fingerprint}.pdf`;
- **invalidation is implicit**: change the cover or the text and the fingerprint changes, so a new object is written and the stale one is simply never requested again. No invalidation hook lives in the services that mutate a communication;
- the same fingerprint is returned as the `ETag`. A client sending a matching `If-None-Match` gets a **304** without dompdf or the storage being touched at all.

The fingerprint cannot see changes to the Blade template, the watermark or the footer. Those are covered by `CommunicationPdfService::RENDER_VERSION`: **bump it in the same commit that changes the layout**, or already-materialized PDFs keep being served with the old one.

The cache is an optimization, never a dependency. If the disk is missing or misconfigured, `Storage::disk()` throws at resolution time — the service catches it, reports it and re-renders on every request, so the export keeps working while degraded. `MvpAppRoutesTest` locks this behaviour in (`the export survives an unavailable PDF cache disk`).

Both routes carry an explicit `throttle:30,1`, tighter than the 60/min group bucket: these are the heaviest responses in the API and originate from a human click, not from a list render.

Purge the materialized copies (they are rebuilt on the next request):

```bash
docker compose exec app php artisan tinker --execute="Storage::disk(config('mvp.communications.pdf_disk'))->deleteDirectory(config('mvp.communications.pdf_prefix'));"
```

## Failure States

| Failure | Observable signal | Operator action |
| --- | --- | --- |
| Workflow start failure | `workflow_failed_at`, audit event, `mvp_stepfunctions_executions_failed_total` | Check state machine ARN and SQS queue URL, then `make refresh-runtime`. |
| SQS task failure | `workflow_tasks.status=failed`, worker log | Inspect DLQ and task error. |
| Text generation failure | `generation_status=failed`, `error_message` on the communication | Check model access, model ID and credentials. |
| Cover degraded | `cover_status=failed`, `mvp_communication_covers_failed_total{reason}` | See "Degraded Cover"; no action needed for isolated events. |
| Cover storage failure | `mvp_communication_cover_storage_failed_total{operation}` | Check bucket, prefix and disk credentials. |
| Stuck communication | `mvp_communication_stuck_processing_total` | Check worker, SQS queue and Step Functions execution. |
| PDF cache unavailable | Reported exception from `CommunicationPdfService`, no 5xx to the user | Preview and export still work but re-render every time: check `MVP_COMMUNICATION_PDF_DISK`, bucket and credentials. |
| Stale PDF layout after a template change | Exported PDF still shows the old layout | `RENDER_VERSION` was not bumped: increment it, or purge the prefix as shown above. |
