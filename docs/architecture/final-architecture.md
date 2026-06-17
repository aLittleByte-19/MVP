# Architettura della PoC

Questo documento descrive l'architettura runtime effettivamente implementata nella PoC: i
confini tra i componenti, cosa gira in locale tramite LocalStack e cosa può essere indirizzato
verso AWS reale, e i principi di qualità che trovano riscontro nel codice.

## Diagramma dell'architettura

![Diagramma E2E dell'architettura runtime e CI](diagrams/final-architecture.drawio.png)

Sorgente editabile: [`diagrams/final-architecture.drawio`](diagrams/final-architecture.drawio)
(draw.io / diagrams.net, loghi ufficiali incorporati); versione vettoriale in
[`final-architecture.drawio.svg`](diagrams/final-architecture.drawio.svg). Le aree colorate
distinguono i piani logici — Frontend, Client/Edge, Applicazione, Config, Dati, Infra locale,
Orchestrazione (LocalStack), AWS reale, Osservabilità e CI/Qualità — mentre lo stile delle
frecce distingue i flussi (vedi legenda nel diagramma): sincrono, workflow asincrono,
errore/DLQ, telemetria, provisioning/infra/CI, build frontend ed encryption. Il diagramma
include anche il legame di control-plane **IAM → Step Functions** (execution role,
least-privilege documentata — non applicata da LocalStack in locale) e il registry **GHCR**
nella pipeline CI (mirror delle immagini base e pubblicazione delle immagini buildate). Gli
export PNG/SVG vanno rigenerati da draw.io dopo ogni modifica (`drawio -x -f png -s 1.6 -b 24 ...`).

## Confine di runtime

| Livello | Componente implementato | Ruolo |
| --- | --- | --- |
| Frontend | SPA React/Vite/TypeScript in `apps/frontend` | Upload, stato di elaborazione, revisione, flussi di anteprima/eliminazione. |
| Edge | Traefik (TLS) e Nginx | HTTPS locale, serving statico della SPA, proxy API, blocco delle superfici `/admin`. |
| API | API JSON Laravel in `app/Http` | Validazione, controlli di tenant, audit event, avvio del workflow. |
| Workflow | Step Functions e SQS (LocalStack) | Orchestrazione production-like con callback task token, end-to-end. |
| Worker | `php artisan poc:workflow:consume` | Ricezione SQS, esecuzione dei task, `SendTaskSuccess`/`SendTaskFailure`, `SendTaskHeartbeat`. |
| OCR | `App\Copilot\Ocr\Services\TextractService` | Integrazione Textract reale, disabilitata di default nelle esecuzioni locali/CI standard. |
| AI | `App\Copilot\Ai\BedrockService` | Integrazione Bedrock reale per split/estrazione/generazione. |
| Storage | Dischi Laravel `s3` o `real_s3` | S3 LocalStack per le demo locali, S3 reale per la validazione Textract/Bedrock. |
| Persistenza | PostgreSQL | Documenti, sotto-documenti, dati estratti, audit e stato dei task di workflow. |
| Cache/sessione | Redis | Cache/sessione e rate limiting; non è la fonte di verità dei dati. |
| Osservabilità | OTel Collector, Prometheus, Tempo, Grafana, Alertmanager | Metriche, trace, dashboard e alert locali. |
| Log | Grafana Alloy, Loki | Raccolta e archiviazione dei log dei container, interrogabili in Grafana. |

## LocalStack e AWS reale

LocalStack fornisce le primitive di orchestrazione production-like testabili in locale:
Step Functions, SQS/DLQ, S3, EventBridge, SSM Parameter Store e Secrets Manager. L'applicazione
parla con i servizi AWS, reali o emulati, **senza cambiare codice**: cambiano solo endpoint e
credenziali.

Alcune primitive sono provisionate ma **non esercitate** dall'applicativo: il bus EventBridge
(con rule e target verso SQS) e l'identità SES esistono in Terraform, ma nessun codice pubblica
eventi o invia email. Le policy IAM (es. execution role di Step Functions) sono definite come
least-privilege *documentata* ma **non applicate da LocalStack** in locale: diventano effettive
solo sul percorso AWS reale.

AWS reale viene usato solo per il percorso critico di validazione AI/OCR, quando sono fornite
credenziali e configurazione esplicite:

- `POC_DOCUMENT_DISK=real_s3`
- `AWS_REAL_REGION`
- `AWS_REAL_ACCESS_KEY_ID`
- `AWS_REAL_SECRET_ACCESS_KEY`
- `AWS_REAL_SESSION_TOKEN`, quando necessario
- `AWS_REAL_S3_BUCKET`
- `AWS_REAL_S3_PREFIX`
- `TEXTRACT_ENABLED=true`
- `BEDROCK_REGION`
- `BEDROCK_MODEL_ID`

I test e la CI standard non chiamano S3, Textract o Bedrock reali.

## Principi di qualità implementati

| Principio di riferimento | Implementazione concreta |
| --- | --- |
| AWS Well-Architected — operational excellence | Avvio ripetibile via Docker/Terraform, endpoint `/health` e `/ready`, target `make verify*`. |
| AWS Well-Architected — reliability | Retry/catch espliciti in Step Functions, heartbeat per task, DLQ SQS, tabella di workflow idempotente. |
| AWS Well-Architected — security | Nessuna UI di amministrazione runtime, nessun segreto reale committato, header di sicurezza e CSP in nginx, matrice IAM a privilegio minimo documentata. |
| Baseline OWASP ASVS/API | Validazione upload server-side, controlli di ownership per tenant, rate limit, confine di autenticazione strutturato. |
| Google SRE — monitoring | Metriche API golden-signal, metriche della pipeline documentale, alert coda/DLQ con runbook. |
| Modello OpenTelemetry | Il Collector riceve OTLP ed esporta metriche verso Prometheus e trace verso Tempo. |
| Logging centralizzato | Grafana Alloy invia i log di ogni container a Loki, correlati in Grafana con metriche e trace. |

## Riferimenti principali

- AWS Well-Architected Framework: https://docs.aws.amazon.com/wellarchitected/latest/framework/welcome.html
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- Google SRE — Monitoring Distributed Systems: https://sre.google/sre-book/monitoring-distributed-systems/
- OpenTelemetry Collector: https://opentelemetry.io/docs/collector/
- Prometheus alerting: https://prometheus.io/docs/alerting/latest/overview/
- Grafana provisioning: https://grafana.com/docs/grafana/latest/administration/provisioning/
