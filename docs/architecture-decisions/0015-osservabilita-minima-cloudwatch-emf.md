# ADR 0015: Osservabilità minima verso CloudWatch (log + EMF)

Status: Accepted, implemented baseline
Date: 2026-08-25

## Context

Dopo l'ADR 0014, RVC16-OB (il vincolo tecnologico del capitolato su osservabilità di metriche,
tracing e log verso un backend gestito) ha un riscontro solo parziale: restano audit trail e
correlation-id, ma Metrics, Alarms e X-Ray (tracing) non hanno più equivalente locale attivo,
come documentato in `docs/architecture/capitolato-traceability.md` §10.

A differenza dell'ADR 0014, qui il trigger non è un carico di produzione reale — l'ADR 0014
diceva esplicitamente che solo quello avrebbe giustificato un ritorno — ma la necessità di dare
un riscontro onesto a un vincolo del capitolato con la minima superficie operativa possibile. Il
testo del BRD originale del proponente Eggon (non l'ADR 0006, che era un'interpretazione già più
ampia) è specifico: *"Observability & Ops: CloudWatch Logs/Metrics/Alarms, X-Ray (tracing)"* —
non impone letteralmente OpenTelemetry né un collector dedicato.

## Decision

Un solo canale di trasporto, stdout/stderr in JSON, senza reintrodurre alcun servizio Docker né
alcuna dipendenza Composer nuova:

- **Log**: nessun codice nuovo. Il canale `stderr` (`config/logging.php`) ha già
  `JsonFormatter` configurato e riceve i campi `request_id`/`correlation_id` iniettati da
  `CorrelateRequests` via `Log::withContext()`. In un ambiente di produzione reale, impostare
  `LOG_CHANNEL=stderr` (vedi `.env.example`) fa sì che queste righe finiscano su stdout/stderr,
  raccolte da CloudWatch Logs tramite il log driver del container host — nessuna chiamata SDK
  applicativa a `PutLogEvents`.
- **Metriche**: un nuovo canale di log `metrics` (stesso `config/logging.php`, `LineFormatter`
  non `JsonFormatter`, per non far avvolgere la riga in un envelope Monolog) riceve righe in
  **CloudWatch Embedded Metric Format (EMF)** — JSON con un blocco `_aws.CloudWatchMetrics` che
  CloudWatch interpreta automaticamente come dato di metrica quando la riga arriva da CloudWatch
  Logs, senza endpoint di scrape né collector. `App\Mvp\Observability\EmfMetricsRecorder`
  costruisce il payload; `App\Http\Middleware\RecordRequestMetrics` lo usa per emettere
  `RequestCount`/`Latency`/`Errors` per richiesta; `php artisan mvp:metrics:dlq-depth`
  (`App\Console\Commands\PublishDlqDepthMetric`) lo usa per `DlqDepth` per pipeline, riusando il
  client `SqsClient` già singleton in `AppServiceProvider`.
- **Alarms**: non provisionati. Un esempio Terraform di riferimento in `infra/aws/README.md`
  mostra come definirli sulle metriche sopra, esplicitamente non applicato: questo repo non ha
  un ambiente AWS reale collegato (`infra/aws/` è solo un placeholder).
- **Tracing distribuito (X-Ray)**: non implementato. La propagazione già esistente di
  `request_id`/`correlation_id` end-to-end (HTTP → coda SQS → worker, tramite `WorkflowContext`)
  è dichiarata equivalente-MVP a un tracing distribuito continuo, stessa logica di
  scope-reduction già accettata per l'autenticazione simulata (ADR 0007).

## Consequences

- Nessun nuovo servizio Docker, nessuna nuova dipendenza Composer (`aws/aws-sdk-php` era già
  presente, riusato solo per `SqsClient`, già registrato).
- Le metriche e i log restano verificabili solo in locale (righe su stderr ispezionabili a mano
  o via test), non su una dashboard CloudWatch reale: non esiste un ambiente AWS reale collegato
  a questo progetto accademico.
- Se il progetto acquisisse un ambiente AWS reale, il log driver del container e il routing
  Alarms→SNS/notifiche andrebbero effettivamente cablati in `infra/aws/`: oggi restano
  documentati, non implementati.
- RVC16 nell'Analisi dei Requisiti va riformulato per riflettere onestamente questa soluzione
  (non promette OpenTelemetry né X-Ray reale né Alarm attivi).

## Alternatives considered

- **Handler Monolog con chiamate dirette `PutLogEvents`/`PutMetricData`**: scartata — richiede
  gestione di sequence token, batching e retry su throttling lato applicativo, la stessa
  complessità operativa appena rimossa dall'ADR 0014, solo spostata da un formato di log a
  chiamate SDK sincrone nel path applicativo.
- **Riproporre un sottoinsieme del vecchio stack (solo Prometheus, senza Grafana/Loki/Tempo)**:
  scartata per lo stesso motivo dell'ADR 0014 — il costo determinante è l'intera categoria
  "infrastruttura di monitoraggio dedicata", non quale singolo strumento.
- **Provisionare comunque Alarms reali via Terraform**: scartata — non verificabile in CI e non
  collegata a un ambiente AWS reale di questo progetto; un esempio documentato è più onesto di
  una risorsa che nessuno applica mai.

## Implementation evidence

- `config/logging.php` (canale `metrics`), `config/services.php` (blocco `metrics`).
- `app/Mvp/Observability/EmfMetricsRecorder.php`.
- `app/Http/Middleware/RecordRequestMetrics.php`, registrato in `bootstrap/app.php` dopo
  `CorrelateRequests`.
- `app/Console/Commands/PublishDlqDepthMetric.php` (`mvp:metrics:dlq-depth`).
- `infra/aws/README.md` (esempio Alarms non applicato).
- `tests/Unit/EmfMetricsRecorderTest.php`, `tests/Feature/RecordRequestMetricsTest.php`,
  `tests/Feature/PublishDlqDepthMetricCommandTest.php`, `tests/Feature/StructuredLoggingTest.php`.

## References

- [ADR 0014](0014-rimozione-stack-osservabilita.md) (questa ADR ne estende parzialmente il
  riscontro, non lo riapre)
- [ADR 0006](0006-observability-and-audit.md) (audit trail e correlation-id, invariati)
- [ADR 0007](0007-authn-authz-boundary.md) (stessa logica di scope-reduction MVP-appropriate)
- AWS CloudWatch Embedded Metric Format: https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch_Embedded_Metric_Format.html

## Related documents

- [`0014-rimozione-stack-osservabilita.md`](0014-rimozione-stack-osservabilita.md)
- [`0006-observability-and-audit.md`](0006-observability-and-audit.md)
- [`../architecture/capitolato-traceability.md`](../architecture/capitolato-traceability.md) (§10)
