# DLQ and Recovery Runbook

## Implemented Path

Step Functions sends callback-token work to SQS. Each pipeline owns its queue, so a backlog in one domain cannot delay the other. Both queues have:

- `visibility_timeout_seconds = 900`
- `message_retention_seconds = 345600`
- `maxReceiveCount = 3`

| Pipeline | Queue | DLQ | Inspect with |
| --- | --- | --- | --- |
| Documents | `mvp-documents` | `mvp-documents-dlq` | `mvp:dlq:list --queue=documents` |
| Communications | `mvp-communications` | `mvp-communications-dlq` | `mvp:dlq:list --queue=communications` |

The workers record every task in `workflow_tasks` by `task_token_hash`, whatever the domain. Successful or skipped tasks are idempotent and are not reprocessed when the same token is seen again.

`ConsumeWorkflowTasks::sendCallback()` classifies a rejected `SendTaskSuccess`/`SendTaskFailure` before deciding whether to delete the SQS message: on a **transient** AWS error (throttling, service unavailable, ...) the message stays queued for a normal SQS redelivery/retry. On a **permanent** error (`TaskTimedOut`, `TaskDoesNotExist`, `InvalidToken` — a retry with the same token can never succeed) the message is deleted immediately even though the callback was rejected, because the business outcome is already tracked in `workflow_tasks`/the domain record; leaving it queued would only produce `maxReceiveCount` retries that populate the DLQ with false failures for work that already finished. A message that does reach the DLQ today is therefore always a transient-error case repeated 3 times, not a permanent one.

```mermaid
flowchart TD
  task["SQS callback-token task"]
  worker["Laravel worker"]
  success["SendTaskSuccess"]
  failure["SendTaskFailure"]
  retry["Step Functions Retry/Catch"]
  dlq["SQS DLQ after receive limit"]
  metrics["Prometheus metrics"]
  alert["Alertmanager alert"]
  operator["Operator runbook"]
  replay["Manual redrive/replay after fix"]

  task --> worker
  worker --> success
  worker --> failure
  failure --> retry
  task --> dlq
  worker --> metrics
  dlq --> metrics
  metrics --> alert
  alert --> operator
  operator --> replay
  replay --> task
```

## Inspect DLQ

```bash
docker compose exec app php artisan mvp:dlq:list --queue=documents
```

The command reads up to 10 messages from the DLQ with `VisibilityTimeout=0`, prints a preview, and records `mvp_dlq_messages_total`.

## Recovery Procedure

1. Open Grafana `Queues and DLQ`.
2. Run `mvp:dlq:list --queue=<pipeline>` and capture message id/body preview.
3. Check `workflow_tasks` for the task status and error message (`subject_type` tells the domain).
4. Check `original_documents.workflow_failure_reason` or `communications.workflow_failure_reason`.
5. Fix the root cause: missing IAM, bad S3 key, model access, or invalid payload.
6. Re-drive from DLQ to source queue using the cloud console/CLI for the target environment.
7. Confirm idempotency by checking that duplicated succeeded tasks are skipped.

The MVP implements diagnostic DLQ inspection and idempotent task records. Automated replay is intentionally not implemented because the final replay mechanism depends on enterprise operational controls and IAM boundaries.

## Relevant Metrics

| Metric | Meaning |
| --- | --- |
| `mvp_sqs_messages_received_total` | Worker received task messages. |
| `mvp_sqs_messages_failed_total` | Worker task failures. |
| `mvp_dlq_messages_total{queue}` | Messages seen during DLQ inspection, per pipeline. |
| `mvp_document_stuck_processing_total` | Documents beyond processing timeout. |
| `mvp_communication_stuck_processing_total` | Communications beyond generation timeout. |
| `mvp_stepfunctions_executions_started_total` | Workflow starts. |
| `mvp_stepfunctions_executions_failed_total` | Workflow start/task failures. |
