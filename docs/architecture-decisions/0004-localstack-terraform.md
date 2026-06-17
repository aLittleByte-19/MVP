# ADR 0004 — Emulazione AWS locale con LocalStack e Terraform

Status: Accepted, implemented
Date: 2026-06-08

## Context

Il runtime locale separa il ciclo di vita dei container dal provisioning delle risorse AWS-like:
i container avviano processi, Terraform crea le risorse AWS-like in LocalStack. L'obiettivo è
esercitare lo stesso codice applicativo previsto per AWS reale senza dipendere dal cloud.

## Decision

Usare Docker Compose per eseguire i processi locali e LocalStack. Usare Terraform sotto
`infra/localstack` per creare le risorse AWS-like locali: code SQS e DLQ, state machine Step
Functions, parametri SSM, secret di Secrets Manager, bus/regole EventBridge, identità SES,
chiave KMS e bucket S3 locale. I moduli Terraform riutilizzabili per la futura infrastruttura
AWS risiedono sotto `infra/modules`.

L'applicazione parla con i servizi AWS, reali o emulati, **senza cambiare codice**: cambiano solo
endpoint e credenziali (vedi [ADR 0005](0005-no-automatic-fallbacks.md)).

## Consequences

- Il bootstrap locale diventa ripetibile e revisionabile.
- Compose e Terraform hanno responsabilità separate.
- Lo stesso modello di risorse può evolvere verso AWS con le differenze documentate
  esplicitamente.
- Lo stato Terraform locale è committato (`terraform.tfstate`): accettabile solo perché contiene
  risorse fittizie di LocalStack.

## Alternatives considered

- **Script imperativi (awslocal/CLI) per creare le risorse**: scartati perché non dichiarativi,
  difficili da rivedere e non riutilizzabili verso AWS reale.
- **Definire le risorse direttamente nel codice applicativo a runtime**: scartato perché mescola
  provisioning e logica di prodotto e impedisce la validazione in CI.

## Implementation evidence

- `infra/localstack/main.tf` (SQS+DLQ, S3+KMS, SSM, Secrets Manager, EventBridge, IAM, SES) e
  `infra/localstack/state-machines/document-pipeline.asl.json`.
- `docker-compose.yml` (servizi `localstack` e `terraform`).
- CI: `terraform fmt -check`, `init`, `validate` in `.github/workflows/ci.yml`.

## References

- Terraform AWS provider: https://registry.terraform.io/providers/hashicorp/aws/latest/docs
- LocalStack — integrazione Terraform: https://docs.localstack.cloud/aws/integrations/infrastructure-as-code/terraform/
- AWS Step Functions con Terraform: https://docs.aws.amazon.com/step-functions/latest/dg/terraform-sfn.html

## Related documents

- [`0003-sqs-instead-of-redis-queue.md`](0003-sqs-instead-of-redis-queue.md)
- [`../runbooks/local-development.md`](../runbooks/local-development.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§5)
