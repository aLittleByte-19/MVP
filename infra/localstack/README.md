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
default frontend path is still S3 local + CloudFront local: Terraform owns the LocalStack S3
bucket, while the `frontend-cloudfront` Docker service fronts that bucket and proxies API calls
to Nginx. This avoids mixing frontend static assets with the optional real S3 bucket used by
documents/Textract.

Frontend static serving flow:

```bash
make frontend-s3-local-deploy
make frontend-cloudfront-local-url
make frontend-serving-local-test
```

The CloudFront emulator is local and Docker-based because the LocalStack image used by this PoC
does not expose the CloudFront API in the default local license. It validates the local
build-to-bucket-to-CDN flow, but it does not replace testing real CloudFront behavior such as TLS
certificates, edge propagation, invalidations, OAC/OAI, response headers policies or AWS IAM
enforcement.
