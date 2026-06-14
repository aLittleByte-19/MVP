# ADR 0006 — Osservabilità e audit trail

Status: Accepted, implemented baseline
Date: 2026-06-08

## Context

Il runtime ha bisogno di telemetria azionabile per un'operatività production-like:
request/correlation ID, log strutturati, record di audit di sicurezza/business, metriche
golden-signal SRE e un gateway di telemetria vendor-neutral.

## Decision

Introdurre la propagazione di request ID e correlation ID su richieste HTTP e record di audit.
Emettere log JSON strutturati ed eseguire localmente OpenTelemetry Collector come gateway di
telemetria.

Creare una tabella `audit_events` append-only per le azioni rilevanti per sicurezza e business.
Esporre le metriche applicative internamente in formato testo Prometheus e farne scrape tramite
OTel Collector verso Prometheus; le trace vanno a Tempo (OTLP) e i log dei container a Loki via
Alloy.

## Consequences

- Log e record di audit non devono includere segreti o contenuto sensibile dei documenti.
- I fallimenti della pipeline devono conservare contesto sufficiente al supporto senza trapelare
  dati.
- Le metriche seguono i golden signal di Google SRE: latency, traffic, errors, saturation.
- L'OTel Collector resta il confine vendor-neutral per l'export futuro di trace e log OTLP.

## Alternatives considered

- **Scrape diretto di ogni servizio da Prometheus**: scartato a favore di un Collector unico, che
  uniforma la pipeline di telemetria (scrape + push) e riduce i target.
- **Solo logging senza metriche/trace strutturate**: scartato perché non copre i golden signal né
  la correlazione cross-componente.

## Implementation evidence

- Correlazione: `app/Http/Middleware/CorrelateRequests.php`; audit: `app/Copilot/Audit/Services/AuditLogger.php`
  e migrazione `audit_events` (append-only).
- Metriche: `app/Copilot/Observability/MetricsRecorder.php`, `PrometheusExporter.php`, endpoint
  `/internal/metrics`.
- Config osservabilità: `docker/otel-collector/`, `docker/prometheus/{prometheus.yml,rules/}`,
  `docker/tempo/`, `docker/loki/`, `docker/alloy/`, `docker/grafana/` (5 dashboard, 10 alert rule).
- Validazione config in CI: `make observability-config` (`promtool`, `otelcol validate`).

## References

- Google SRE — Monitoring Distributed Systems: https://sre.google/sre-book/monitoring-distributed-systems/
- OpenTelemetry docs: https://opentelemetry.io/docs/
- OpenTelemetry logs: https://opentelemetry.io/docs/specs/otel/logs/

## Related documents

- [`0005-no-automatic-fallbacks.md`](0005-no-automatic-fallbacks.md)
- [`../runbooks/observability.md`](../runbooks/observability.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§14)
