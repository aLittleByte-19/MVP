# AWS Well-Architected Mapping

This mapping explains how the target PoC migration aligns with the AWS Well-Architected pillars. It is not a formal AWS review.

| Pillar | PoC choice | Reason | Current limit | Product Baseline target |
| --- | --- | --- | --- | --- |
| Operational excellence | ADRs, runbooks, Terraform for LocalStack, expanded CI target. | Make operations repeatable and decisions visible. | Local bootstrap is automated; CI is still limited. | Automated local/CI bootstrap, smoke tests, runbooks, and observable deployments. |
| Security | SSM/Secrets split, no credential editing in the app UI, OIDC planned, RBAC/ABAC target, audit table target. | Reduce credential exposure and enforce permissions server-side. | Routes are still public and use no enterprise identity boundary. | Enterprise identity boundary, scoped IAM role, backend authorization, safe logging, audit. |
| Reliability | SQS with DLQ, Step Functions workflow, explicit failure states, no automatic main-flow fallbacks. | Make failures visible and recoverable. | Step Functions is provisioned but not yet orchestrating the worker end-to-end. | SQS/DLQ workers, state machine failure handling, idempotency, retries/backoff where appropriate. |
| Performance efficiency | PHP-FPM with OPcache, SPA separation, presigned S3 uploads, async OCR/extraction. | Avoid routing large uploads through app workers and keep long tasks asynchronous. | Current upload goes through Laravel and splits documents synchronously inside a queued job. | Direct S3 upload, async Textract/Bedrock workflow, measured latency and saturation. |
| Cost optimization | LocalStack for local/CI AWS-like tests; real AWS smoke tests conditional. | Avoid unnecessary AWS spend while preserving portability. | No cost controls or real AWS usage model yet. | Explicit local vs real AWS modes, bounded smoke tests, lifecycle policies. |
| Sustainability | Container hardening, smaller images target, conditional real cloud tests, resource lifecycle through Terraform. | Reduce waste from oversized images and unnecessary cloud runs. | Current image is not minimized and Compose keeps broad services. | Multi-stage images, focused services, repeatable teardown, limited real AWS smoke scope. |

## Primary Reference

- AWS Well-Architected Framework: https://docs.aws.amazon.com/wellarchitected/latest/framework/the-pillars-of-the-framework.html
