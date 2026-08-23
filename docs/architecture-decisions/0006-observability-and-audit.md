# ADR 0006: Osservabilità e audit trail

Status: Metrics/dashboard/OTel Collector superseded by [0014](0014-rimozione-stack-osservabilita.md); audit trail e correlation ID Accepted, implemented baseline
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
- L'OTel Collector resta il confine vendor-neutral per l'export futuro di trace e log OTLP, ma per
  le metriche è un gateway di **trasporto**: non riscrive i nomi (`add_metric_suffixes: false`).
  Delegargli la normalizzazione significherebbe far dipendere i nomi delle serie da un default
  upstream, che è come i pannelli OCR sono rimasti vuoti pur avendo il dato.
- Le metriche di dominio sono dichiarate prima di essere osservate (`DomainMetricCatalog`): una
  serie esiste a zero dal primo scrape, quindi i pannelli non mostrano "No data" su un ambiente
  appena avviato e le regole con `== 0` hanno qualcosa su cui valutare. Il costo è che aggiungere
  una metrica richiede due modifiche invece di una, ed è voluto: è il punto in cui il disallineamento
  fra ciò che si emette e ciò che si interroga diventa visibile.
- Un fallimento di raccolta degrada la singola famiglia di metriche, non l'intera esposizione.

## Alternatives considered

- **Scrape diretto di ogni servizio da Prometheus**: scartato a favore di un Collector unico, che
  uniforma la pipeline di telemetria (scrape + push) e riduce i target.
- **Solo logging senza metriche/trace strutturate**: scartato perché non copre i golden signal né
  la correlazione cross-componente.

## Implementation evidence

- Correlazione: `app/Http/Middleware/CorrelateRequests.php`; audit: `app/Mvp/Audit/Services/AuditLogger.php`
  e migrazione `audit_events` (append-only).
- Metriche: `app/Mvp/Observability/MetricsRecorder.php`, `PrometheusExporter.php`,
  `DomainMetricCatalog.php`, `DomainMetricDefinition.php`, `DlqDepthProbe.php`, endpoint
  `/internal/metrics`.
- Dashboard: ciascuna dichiara in testa la domanda a cui risponde e gli alert correlati; la
  struttura segue i golden signal per l'API, l'imbuto di pipeline per i due domini e il metodo
  USE per le code (vedi `../runbooks/observability.md`).
- Config osservabilità: `docker/otel-collector/`, `docker/prometheus/{prometheus.yml,rules/}`,
  `docker/tempo/`, `docker/loki/`, `docker/alloy/`, `docker/grafana/` (6 dashboard, 16 alert rule).
- Validazione config in CI: `make observability-config` (`promtool`, `otelcol validate`).
- Contratto metriche: `tests/Feature/ObservabilityContractTest.php` più l'helper
  `tests/Support/MetricsContract.php`, eseguiti da `make verify-backend`.

## References

- Google SRE: Monitoring Distributed Systems: https://sre.google/sre-book/monitoring-distributed-systems/
- OpenTelemetry docs: https://opentelemetry.io/docs/
- OpenTelemetry logs: https://opentelemetry.io/docs/specs/otel/logs/

## Related documents

- [`0005-no-automatic-fallbacks.md`](0005-no-automatic-fallbacks.md)
- [`../runbooks/observability.md`](../runbooks/observability.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§14)
