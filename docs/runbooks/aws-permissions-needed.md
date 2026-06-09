# AWS Permissions Needed

The PoC must not use static AWS credentials in GitHub Actions. Real AWS smoke tests and future deployments require an IAM role assumable through GitHub Actions OIDC.

## OIDC Trust

Needed:

- GitHub OIDC provider configured in the AWS account.
- IAM role with trust policy scoped to the repository, branch/environment, and intended workflow.
- `id-token: write` permission in the workflow.

## Minimum Permission Areas

The eventual AWS role needs scoped permissions for:

- S3 bucket/object access for document upload, metadata, read, and lifecycle-managed storage.
- S3 presigned URL generation through application runtime permissions.
- Textract async document text detection and result retrieval.
- Bedrock Runtime model invocation for approved model IDs.
- SQS queue and DLQ send/receive/delete/change visibility/get attributes.
- Step Functions state machine start/describe/get execution history.
- EventBridge put events and rule/target access where needed.
- SES identity/send permissions for dispatch smoke tests.
- SSM Parameter Store read access for non-secret configuration.
- Secrets Manager read access for application secrets.
- CloudWatch Logs/metrics or OpenTelemetry collector export target permissions when real AWS observability is introduced.
- ECR/GHCR-equivalent deployment permissions only when the deployment target is defined.

## Local Real-AWS Smoke

The local hybrid mode (`POC_DOCUMENT_DISK=real_s3`, `TEXTRACT_ENABLED=true`) reads a single shared credential set from `.env` (`AWS_REAL_ACCESS_KEY_ID` / `AWS_REAL_SECRET_ACCESS_KEY` / `AWS_REAL_SESSION_TOKEN`) used by S3, Textract and Bedrock. That principal must therefore hold S3 object access, Textract async detection and Bedrock `InvokeModel` at once. S3 and Textract must share the same region; Bedrock may use a different region where the model is enabled.

## Current Blockers

- No enterprise AWS account role ARN has been provided.
- No OIDC trust policy has been provided.
- No Bedrock model access policy has been confirmed.
- No S3/Textract regional constraints have been confirmed.
- No SES sandbox/identity status has been confirmed.

Until these are available, real AWS smoke tests must remain conditional and non-blocking.

