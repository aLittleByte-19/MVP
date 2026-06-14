# ADR 0004 - LocalStack And Terraform

Status: Accepted and implemented

## Context

The local runtime separates container lifecycle from AWS-like resource provisioning. Containers start processes; Terraform creates AWS-like resources in LocalStack.

## Decision

Use Docker Compose to run local processes and LocalStack. Use Terraform under `infra/localstack` to create AWS-like local resources: SQS queues and DLQ, Step Functions state machine, SSM parameters, Secrets Manager secrets, EventBridge bus/rules, SES identity, and local S3 bucket for contract/local tests.

Terraform modules for reusable future AWS infrastructure belong under `infra/modules`.

## Consequences

- Local bootstrap becomes repeatable and reviewable.
- Compose and Terraform have separate responsibilities.
- The same resource model can evolve toward AWS with explicit differences documented.

## References

- Terraform AWS provider: https://registry.terraform.io/providers/hashicorp/aws/latest/docs
- LocalStack Terraform integration: https://docs.localstack.cloud/aws/integrations/infrastructure-as-code/terraform/
- AWS Step Functions with Terraform: https://docs.aws.amazon.com/step-functions/latest/dg/terraform-sfn.html
