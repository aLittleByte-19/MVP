# ADR 0003 - SQS Instead Of Redis Queue

Status: Accepted for migration baseline

## Context

The current PoC uses Redis as the queue backend and starts `php artisan queue:work redis` in Docker Compose. The target architecture requires Amazon SQS as the primary queue and explicitly excludes Laravel Horizon.

## Decision

Use Laravel's SQS queue driver for the main queue. In local development, SQS is provided by LocalStack and provisioned through Terraform. Configure a DLQ and worker process for document pipeline jobs.

Redis remains in the architecture for cache, sessions, rate limiting, and temporary in-memory data. Redis must not be the primary queue backend, and Horizon must not be introduced.

## Consequences

- Queue workers must be configured for SQS and LocalStack.
- CI smoke tests must verify SQS and DLQ resources locally.
- Failed jobs and retry semantics must be explicit and observable.

## References

- Laravel queues: https://laravel.com/docs/12.x/queues
- Amazon SQS: https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/welcome.html
- LocalStack SQS: https://docs.localstack.cloud/aws/services/sqs/

