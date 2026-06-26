# LocalStack Terraform

This directory contains the local AWS-like infrastructure contract for the PoC.

LocalStack endpoint:

```bash
http://localhost:4566
```

Commands:

```bash
make infra-init
make infra-plan
make infra-apply
make infra-destroy
```

Resources modelled here:

- SQS queue and DLQ;
- S3 bucket for local/contract document storage;
- S3 bucket for Angular static assets;
- Step Functions state machine for the document workflow;
- SSM Parameter Store parameters;
- Secrets Manager secrets;
- EventBridge bus, rule, and SQS target;
- SES local sender identity.

Compose starts LocalStack and application processes. Terraform creates AWS-like resources. The
default frontend path is S3 local + a local CDN emulator: Terraform owns the LocalStack S3
bucket, while the `frontend-cloudfront` Docker service — a second Nginx that emulates the role of
a CDN/edge (not Amazon CloudFront) — fronts that bucket and proxies API calls to the application
Nginx. It is a separate container on purpose: the application Nginx is a production image and must
not reference LocalStack, so the emulated S3 serving stays confined to a local-only scaffold. This
also avoids mixing frontend static assets with the optional real S3 bucket used by
documents/Textract.

Frontend static serving flow:

```bash
make frontend-s3-local-deploy
make frontend-cloudfront-local-url
make frontend-serving-local-test
```

The CDN emulator is local and Docker-based (a plain Nginx) because the LocalStack image used by
this PoC does not expose the CloudFront API in the default local license. It validates the local
build-to-bucket-to-edge flow, but it does not replace a real CDN: in production the role would be
filled by AWS CloudFront (TLS certificates, edge propagation, invalidations, OAC/OAI, response
headers policies, AWS IAM enforcement).
