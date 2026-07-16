# ADR 0003 — SQS come backend di coda invece di Redis Queue

Status: Accepted, implemented
Date: 2026-06-08

## Context

L'elaborazione documentale è asincrona e va spostata fuori dal ciclo HTTP. Serve un backend di
messaging affidabile, con dead-letter e semantica di retry esplicita, allineato al modello AWS
di destinazione. In sviluppo locale SQS è fornito da LocalStack; Laravel Horizon è
intenzionalmente escluso.

## Decision

Usare Amazon SQS come backend di coda primario (`QUEUE_CONNECTION=sqs`), provisionato in locale
via Terraform su LocalStack con coda dei documenti e relativa DLQ. La pipeline documentale **non**
usa i job Laravel `dispatch`/`queue:work`: l'orchestrazione è affidata a Step Functions (vedi
[ADR 0004](0004-localstack-terraform.md)) che pubblica su SQS task con callback task token,
consumati dal worker dedicato `mvp:workflow:consume`.

Redis resta nell'architettura per cache, sessioni, rate limiting e dati temporanei in memoria.
Redis non deve essere il backend di coda primario e Horizon non va introdotto.

## Consequences

- Il worker deve essere configurato per SQS/LocalStack e gira come servizio compose dedicato.
- Lo smoke CI deve verificare la presenza delle risorse SQS e DLQ in locale.
- I fallimenti e la semantica di retry devono essere espliciti e osservabili (retry ASL,
  redrive verso DLQ, alert `QueueBacklogHigh`/`DLQNotEmpty`).

## Alternatives considered

- **Redis come coda primaria (driver `redis` o Horizon)**: scartato perché Redis è già usato per
  cache/sessioni/rate limit (eviction `volatile-lru`) e non offre il modello DLQ/visibility di SQS
  né l'allineamento al target AWS.
- **Job Laravel su SQS (`dispatch` + `queue:work`)**: scartato a favore del callback pattern di
  Step Functions, che tiene lo stato del workflow fuori dall'esecutore e rende i passi espliciti.

## Implementation evidence

- `infra/localstack/main.tf`: `aws_sqs_queue.documents` (+ `documents_dlq`, redrive `maxReceiveCount` 3),
  `QUEUE_CONNECTION=sqs`.
- Worker: `app/Console/Commands/ConsumeWorkflowTasks.php` (servizio `queue` in `docker-compose.yml`).
- Redis per cache/sessioni/rate limiting: `config/cache.php`, `config/database.php`, `routes/api.php`.

## References

- Amazon SQS: https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/welcome.html
- LocalStack SQS: https://docs.localstack.cloud/aws/services/sqs/
- AWS Step Functions — callback con task token: https://docs.aws.amazon.com/step-functions/latest/dg/connect-to-resource.html

## Related documents

- [`0004-localstack-terraform.md`](0004-localstack-terraform.md)
- [`0005-no-automatic-fallbacks.md`](0005-no-automatic-fallbacks.md)
- [`../runbooks/document-pipeline.md`](../runbooks/document-pipeline.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§9)
