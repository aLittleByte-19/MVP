# IAM Permissions Matrix

This matrix is a PoC-derived proposal. It is not a final production IAM policy.

| Component | IAM action | Resource | Reason | Environment | Notes |
| --- | --- | --- | --- | --- | --- |
| Laravel API | `s3:PutObject` | Real document bucket/prefix | Store uploaded document for OCR/AI | Real AWS smoke/future prod | Prefer bucket prefix scoped to tenant/environment. |
| Laravel API | `s3:GetObject` | Real document bucket/prefix | Read original file for Bedrock document input | Real AWS smoke/future prod | Required when processing reads from S3-backed disk. |
| Laravel API | `states:StartExecution` | Document pipeline state machine | Start document workflow | LocalStack/future prod | LocalStack uses Terraform-created role/resources. |
| Worker queue | `sqs:ReceiveMessage` | Document task queue | Consume callback-token tasks | LocalStack/future prod | Source queue only. |
| Worker queue | `sqs:DeleteMessage` | Document task queue | Acknowledge completed/failure-reported task | LocalStack/future prod | Delete only after callback decision. |
| Worker queue | `sqs:GetQueueAttributes` | Document task queue/DLQ | Readiness and diagnostics | LocalStack/future prod | Used by health and DLQ checks. |
| Worker callback | `states:SendTaskSuccess` | Step Functions callback token | Resume successful task | LocalStack/future prod | Scoped by state machine/execution where supported. |
| Worker callback | `states:SendTaskFailure` | Step Functions callback token | Resume failure path | LocalStack/future prod | Required for explicit failure handling. |
| Worker OCR | `textract:StartDocumentTextDetection` | `*` or supported scoped resource | Start async OCR | Real AWS only | Textract resource scoping is limited for some APIs. |
| Worker OCR | `textract:GetDocumentTextDetection` | `*` or supported scoped resource | Poll OCR result | Real AWS only | Validate with target account policy simulator. |
| Worker AI | `bedrock:InvokeModel` / `bedrock:Converse` | Selected model or inference profile | Split/extract/generate content | Real AWS only | Model access is account/region specific. |
| Config loader | `ssm:GetParameter` / `ssm:GetParametersByPath` | PoC SSM path | Load runtime configuration | LocalStack/future prod | Read-only. |
| Config loader | `secretsmanager:GetSecretValue` | Runtime secret | Load secrets | LocalStack/future prod | Read-only; no list permissions needed. |
| CI AWS smoke | `sts:AssumeRoleWithWebIdentity` | Enterprise provided role | OIDC smoke | GitHub Actions manual | No static AWS credentials in CI. |
