# Architecture Assessment

Date: 2026-06-08

This document records the current local production-like baseline for the Laravel application.

## Current Repository Shape

| Area | Current state |
| --- | --- |
| Frontend | Blade views and static assets remain in the root Laravel app. A separate SPA is still a future migration. |
| Backend Laravel | Laravel 12 application at repository root. Domain code lives under `app/Poc`. |
| HTTP entry points | Web UI under `/`, console under `/admin`, JSON APIs under `/poc/api/*` and compatibility `/api/v1/*`, health endpoints `/health` and `/ready`. |
| Docker/Compose | Immutable PHP-FPM and Nginx images, explicit `migrate` release job, SQS worker, PostgreSQL, Redis, LocalStack, and Terraform tool service. |
| Runtime config | App containers receive only bootstrap `CONFIG_*` values. Laravel loads runtime values from SSM Parameter Store and Secrets Manager before framework boot. |
| Infrastructure | Terraform in `infra/local` runs through Docker Compose and creates S3, SQS/DLQ, EventBridge, Step Functions, SES, SSM parameters, and runtime secrets in LocalStack. |
| Queue/jobs | `ProcessOriginalDocumentJob` runs on SQS locally. Redis remains for cache and sessions. |
| Storage | Document files use the S3 disk backed by LocalStack. |
| AI services | Bedrock is required for generation, document split, and field extraction. Missing model/configuration or service errors surface as explicit application failures. |
| Tests | Pest tests isolate runtime configuration with `CONFIG_SOURCE=env` and use focused mocks for AI/storage/queue boundaries. |
| Admin console | Read-only operational state plus explicit data reset. It no longer edits runtime configuration or credentials. |

## Remaining Gaps

- Routes are still public; identity and RBAC/ABAC are not implemented.
- Uploads still pass through Laravel instead of presigned S3 finalization.
- Step Functions is provisioned but not yet orchestrating the Laravel worker end-to-end.
- SES dispatch, append-only audit events, OpenTelemetry, OpenAPI and separate React SPA are still future work.
- CI does not yet run Terraform validation, image scanning, LocalStack smoke tests or cloud smoke tests.

## Replacement Map

| Existing area | Current direction |
| --- | --- |
| Blade dashboard | Keep operational while SPA/API separation is introduced later. |
| Root app config | Use SSM Parameter Store for non-sensitive values and Secrets Manager for secrets. |
| Runtime bootstrap scripts | Use explicit Make targets and Terraform; containers do not mutate application state on startup. |
| Redis queue | Use SQS workers with DLQ modeled in Terraform. |
| Automatic AI substitutions | Use failed pipeline states and structured logs. Test doubles stay in tests only. |
| Terraform execution | Use the Compose `terraform` service. |

## Primary References

- AWS Well-Architected Framework: https://docs.aws.amazon.com/wellarchitected/latest/framework/the-pillars-of-the-framework.html
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- Laravel deployment and queues: https://laravel.com/docs/12.x/deployment and https://laravel.com/docs/12.x/queues
- AWS SSM Parameter Store: https://docs.aws.amazon.com/systems-manager/latest/userguide/systems-manager-parameter-store.html
- AWS Secrets Manager: https://docs.aws.amazon.com/secretsmanager/latest/userguide/intro.html
- LocalStack Terraform integration: https://docs.localstack.cloud/aws/integrations/infrastructure-as-code/terraform/
