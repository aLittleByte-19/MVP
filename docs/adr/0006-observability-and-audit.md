# ADR 0006 - Observability And Audit

Status: Accepted for migration baseline

## Context

The current app logs some failures, but it does not have consistent JSON logs, request IDs, correlation IDs, OpenTelemetry traces/metrics/log correlation, or an append-only audit table.

## Decision

Introduce request ID and correlation ID propagation across API requests, queue jobs, workflow steps, domain events, dispatch attempts, and audit records. Emit structured JSON logs and add OpenTelemetry locally through a collector.

Create an append-only `audit_events` table for security- and business-relevant actions, including upload requested/finalized, pipeline started, OCR completed/failed, Bedrock completed/failed, output validation completed/failed, dispatch requested/completed/failed, access denied events, and document status changes.

## Consequences

- Logs and audit records must not include secrets or sensitive document content.
- Pipeline failures must retain enough context for support without leaking data.
- Metrics should align with Google SRE golden signals: latency, traffic, errors, and saturation.

## References

- Google SRE monitoring: https://sre.google/sre-book/monitoring-distributed-systems/
- OpenTelemetry docs: https://opentelemetry.io/docs/
- OpenTelemetry logs: https://opentelemetry.io/docs/specs/otel/logs/

