# ADR 0014: Rimozione dello stack Prometheus/Grafana

Status: Accepted, implemented
Date: 2026-08-23

## Context

ADR 0006 aveva introdotto un intero stack di osservabilità operativa in stile produzione AWS:
OpenTelemetry Collector, Prometheus, Grafana (6 dashboard), Alertmanager, Tempo e Loki/Alloy, più
il codice applicativo che li alimentava (`App\Mvp\Observability\{DomainMetricCatalog,
PrometheusExporter, DlqDepthProbe, MetricsRecorder}`, l'endpoint `/internal/metrics`, il middleware
`RecordHttpMetrics`).

Una revisione dell'architettura complessiva (frontend e backend) ha verificato che questa scelta
non rispondeva a un bisogno reale del progetto, ma a un allineamento esplicito al "modello AWS di
destinazione" (vedi ADR 0003/0004, che seguono lo stesso principio per code e infrastruttura). A
differenza di quelle scelte — dove l'architettura resta proporzionata anche a un progetto piccolo,
perché un dominio ben isolato non costa di più in assenza di traffico — un intero stack di
telemetria ha un costo che cresce con l'infrastruttura stessa (7 servizi Docker aggiuntivi, una
rete dedicata, config da tenere valida in CI, dashboard da tenere sincronizzate col codice) e non
con la complessità di business. Per un progetto con due domini e traffico di fatto nullo, quel
costo non è giustificato da un bisogno operativo reale.

## Decision

Rimuovere l'intero stack di osservabilità operativa e il codice che lo alimenta:

- `app/Mvp/Observability/` (`DomainMetricCatalog`, `DomainMetricDefinition`, `PrometheusExporter`,
  `DlqDepthProbe`, `MetricsRecorder`), l'endpoint `GET /internal/metrics` e il middleware
  `RecordHttpMetrics`.
- I servizi Docker `otel-collector`, `prometheus`, `tempo`, `alertmanager`, `grafana`, `loki`,
  `alloy`, la rete `observability` e i relativi volumi.
- Le dashboard Grafana, le regole di allarme Prometheus e le config di Alloy/Loki/Tempo/OTel
  Collector.
- Il contratto di test dedicato (`tests/Feature/ObservabilityContractTest.php`,
  `tests/Support/MetricsContract.php`) e la validazione CI (`make observability-config`).

**Restano invariati**, perché sono un sistema distinto che ADR 0006 documentava nello stesso
verbale senza condividerne il codice:

- L'audit trail di compliance (`app/Mvp/Audit/Services/AuditLogger.php`, tabella `audit_events`
  append-only).
- I correlation/request ID (`app/Http/Middleware/CorrelateRequests.php`).
- Le metriche mostrate nell'app agli utenti (`MvpStateService` → `GET /api/v1/state` → pannelli
  Angular): sono un sistema separato, senza alcuna dipendenza di codice dallo stack rimosso,
  verificato esplicitamente prima di questa rimozione.
- Il comando diagnostico manuale `php artisan mvp:dlq:list`, che interroga SQS per conto proprio
  e resta l'unico modo per ispezionare la profondità di una DLQ dopo la rimozione di
  `DlqDepthProbe`.

`traefik` resta come router di edge (instrada verso `edge-cdn`/`nginx`), ma perde
l'instradamento verso le dashboard rimosse e l'esposizione delle proprie metriche Prometheus.

## Consequences

- 7 servizi Docker in meno da costruire, avviare e tenere in salute in locale e in CI: il tempo di
  `docker compose up`/`make setup` si riduce, così come la superficie da capire per chi arriva sul
  progetto.
- Nessuna dashboard operativa: la diagnosi di un incidente torna a passare dai log applicativi
  strutturati e dal comando `mvp:dlq:list`, non più da un pannello. Per la scala attuale del
  progetto (nessun traffico reale, nessun turno di reperibilità) questo è un compromesso
  accettabile; se il progetto crescesse verso un carico di produzione reale, la scelta andrebbe
  rivalutata con una nuova ADR.
- I file storici di ADR 0006 restano come sono (gli ADR non si riscrivono): il suo status viene
  aggiornato per segnalare che la parte su OTel/Prometheus/Grafana è superata da questa decisione,
  mentre la parte su audit/correlation resta accettata e implementata.

## Alternatives considered

- **Tenere solo Prometheus + Grafana, senza Tempo/Loki/Alloy**: scartata perché il costo
  determinante non è quale singolo strumento, ma l'intera categoria "infrastruttura di
  monitoraggio dedicata" per un progetto senza operatività reale da monitorare.
- **Sostituire con un servizio SaaS esterno (es. Grafana Cloud gratuito)**: scartata per lo stesso
  motivo — aggiunge una dipendenza esterna e credenziali da gestire per un bisogno che non esiste
  ancora.

## Implementation evidence

- Rimozione: commit di questa stessa serie su `app/Mvp/Observability/`, `docker-compose.yml`,
  `docker/{otel-collector,prometheus,tempo,alertmanager,loki,alloy,grafana}/`,
  `.github/workflows/ci.yml`, `Makefile`.
- Cosa resta: `app/Mvp/Audit/Services/AuditLogger.php`, `app/Http/Middleware/CorrelateRequests.php`,
  `app/Mvp/Support/MvpStateService.php`, `app/Console/Commands/ListDlqMessages.php`.

## References

- [ADR 0006](0006-observability-and-audit.md) (in parte superata da questa decisione)
- [ADR 0003](0003-sqs-instead-of-redis-queue.md), [ADR 0004](0004-localstack-terraform.md) (stesso
  principio di allineamento al modello AWS di destinazione, non superati da questa ADR)

## Related documents

- [`0006-observability-and-audit.md`](0006-observability-and-audit.md)
