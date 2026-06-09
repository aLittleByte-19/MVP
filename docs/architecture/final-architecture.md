# Final PoC Architecture

This document describes the implemented PoC architecture. It is a strong end-to-end PoC baseline, not a production-ready platform.

## Runtime Boundary

| Layer | Implemented component | Scope |
| --- | --- | --- |
| Frontend | React/Vite/TypeScript SPA in `apps/frontend` | Upload, processing status, review, delete/preview flows. |
| Edge | Traefik TLS and Nginx | Local HTTPS, static SPA serving, API proxy, `/admin` hard-block. |
| API | Laravel JSON API in `app/Http` | Validation, tenant checks, audit events, workflow start. |
| Workflow | LocalStack Step Functions and SQS | Production-like callback-token orchestration. |
| Worker | `php artisan poc:workflow:consume` | SQS receive, task execution, `SendTaskSuccess`/`SendTaskFailure`. |
| OCR | `App\Copilot\Ocr\Services\TextractService` | Real Textract integration, disabled in standard local/CI runs. |
| AI | `App\Copilot\Ai\BedrockService` | Real Bedrock integration for split/extraction/generation. |
| Storage | `s3` or `real_s3` Laravel disks | LocalStack S3 for local demos, real S3 for Textract/Bedrock validation. |
| Persistence | PostgreSQL | Documents, sub-documents, extraction data, audit and workflow task state. |
| Cache/session | Redis | Runtime cache/session support, not source of record. |
| Observability | OTel Collector, Prometheus, Tempo, Grafana, Alertmanager | Local metrics, traces, dashboards and alerts. |

## LocalStack vs Real AWS

LocalStack is used for production-like orchestration primitives that can be tested locally: Step Functions, SQS/DLQ, S3, EventBridge, SSM Parameter Store and Secrets Manager.

Real AWS is used only for the critical AI/OCR validation path when explicit credentials and configuration are provided:

- `POC_DOCUMENT_DISK=real_s3`
- `AWS_REAL_REGION`
- `AWS_REAL_ACCESS_KEY_ID`
- `AWS_REAL_SECRET_ACCESS_KEY`
- `AWS_REAL_SESSION_TOKEN`, when needed
- `AWS_REAL_S3_BUCKET`
- `AWS_REAL_S3_PREFIX`
- `TEXTRACT_ENABLED=true`
- `BEDROCK_REGION`
- `BEDROCK_MODEL_ID`

Standard tests and CI do not call real S3, Textract or Bedrock.

## Implemented Quality Principles

| Source principle | Concrete implementation |
| --- | --- |
| AWS Well-Architected operational excellence | Repeatable Docker/Terraform startup, health/readiness endpoints, `make verify*` targets. |
| AWS Well-Architected reliability | Explicit Step Functions retries/catches, SQS DLQ, idempotent workflow task table. |
| AWS Well-Architected security | No runtime admin UI, no committed real secrets, least-required IAM matrix documented. |
| OWASP ASVS/API baseline | Server-side upload validation, tenant ownership checks, rate limits, structured auth boundary. |
| Google SRE monitoring | Golden-signal API metrics, document pipeline metrics, queue/DLQ alerts and runbooks. |
| OpenTelemetry model | Collector receives OTLP, exports metrics to Prometheus and traces to Tempo. |

## Primary References

- AWS Well-Architected Framework: https://docs.aws.amazon.com/wellarchitected/latest/framework/welcome.html
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- Google SRE Monitoring Distributed Systems: https://sre.google/sre-book/monitoring-distributed-systems/
- OpenTelemetry Collector: https://opentelemetry.io/docs/collector/
- Prometheus alerting: https://prometheus.io/docs/alerting/latest/overview/
- Grafana provisioning: https://grafana.com/docs/grafana/latest/administration/provisioning/
