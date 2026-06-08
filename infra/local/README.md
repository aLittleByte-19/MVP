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
- S3 bucket for local/contract storage tests;
- Step Functions state machine for the document workflow;
- SSM Parameter Store parameters;
- Secrets Manager secrets;
- EventBridge bus, rule, and SQS target;
- SES local sender identity.

Compose starts LocalStack and application processes. Terraform creates AWS-like resources.

