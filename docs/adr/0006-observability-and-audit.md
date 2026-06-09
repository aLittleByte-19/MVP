# ADR 0006 - Observability And Audit

Status: Accepted and implemented baseline

## Context

The runtime needs actionable telemetry for production-like operation: request/correlation IDs, structured logs, security/business audit records, SRE golden-signal metrics, and a vendor-neutral telemetry gateway.

## Decision

Introduce request ID and correlation ID propagation across HTTP requests and audit records. Emit structured JSON logs and run OpenTelemetry Collector locally as the telemetry gateway.

Create an append-only `audit_events` table for security- and business-relevant actions. Expose application metrics internally in Prometheus text format and scrape them through OTel Collector into Prometheus.

## Consequences

- Logs and audit records must not include secrets or sensitive document content.
- Pipeline failures must retain enough context for support without leaking data.
- Metrics align with Google SRE golden signals: latency, traffic, errors, and saturation.
- OTel Collector remains the vendor-neutral boundary for future OTLP traces and log export.

## References

- Google SRE monitoring: https://sre.google/sre-book/monitoring-distributed-systems/
- OpenTelemetry docs: https://opentelemetry.io/docs/
- OpenTelemetry logs: https://opentelemetry.io/docs/specs/otel/logs/
