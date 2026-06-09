# Alittlebyte Document Pipeline

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white" />
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white" />
  <img src="https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=111111" />
  <img src="https://img.shields.io/badge/Vite-6-646CFF?logo=vite&logoColor=white" />
  <img src="https://img.shields.io/badge/TypeScript-5.7-3178C6?logo=typescript&logoColor=white" />
  <img src="https://img.shields.io/badge/Docker_Compose-runtime-2496ED?logo=docker&logoColor=white" />
  <img src="https://img.shields.io/badge/Terraform-1.10-844FBA?logo=terraform&logoColor=white" />
  <img src="https://img.shields.io/badge/LocalStack-4.5-00A6B2" />
  <img src="https://img.shields.io/badge/OpenTelemetry-Collector-000000?logo=opentelemetry&logoColor=white" />
  <img src="https://img.shields.io/badge/Prometheus-3.9-E6522C?logo=prometheus&logoColor=white" />
  <img src="https://img.shields.io/badge/Grafana-12.3-F46800?logo=grafana&logoColor=white" />
</p>

<p align="center">
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/pint.yml"><img src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/pint.yml?label=Pint" /></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/pest.yml"><img src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/pest.yml?label=Pest" /></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/quality.yml"><img src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/quality.yml?label=PHPStan" /></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/containers.yml"><img src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/containers.yml?label=Trivy" /></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/accessibility.yml"><img src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/accessibility.yml?label=Axe" /></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/accessibility.yml"><img src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/accessibility.yml?label=Pa11y" /></a>
</p>

Applicazione per generazione assistita di comunicazioni interne e analisi documentale PDF. Lo stack locale e completamente orchestrato con Docker Compose: non richiede PHP, Composer, Node.js, npm, Terraform o tool AWS installati sull'host.

## Funzionalita

- SPA React/Vite/TypeScript servita da Nginx.
- API JSON Laravel versionata sotto `/api/v1`.
- Generazione di comunicazioni tramite Bedrock.
- Upload PDF, archiviazione S3, OCR Textract opzionale, split documentale e persistenza dei risultati.
- Workflow asincrono Step Functions + SQS task token, con DLQ e worker dedicato.
- Configurazione runtime da SSM Parameter Store e Secrets Manager.
- Observability con OpenTelemetry Collector, Prometheus, Tempo, Alertmanager e Grafana.
- Superficie runtime ridotta: `/admin` e vecchi endpoint legacy rispondono 404.

## Architettura

```mermaid
flowchart LR
  Browser[Browser] --> Traefik[Traefik HTTP/TLS]
  Traefik --> Nginx[Nginx + SPA]
  Nginx --> Laravel[Laravel API]
  Laravel --> Postgres[(PostgreSQL)]
  Laravel --> Redis[(Redis)]
  Laravel --> S3[(S3 / LocalStack)]
  Laravel --> SFN[Step Functions]
  SFN --> SQS[SQS task queue]
  SQS --> Worker[Workflow worker]
  Worker --> Textract[Textract]
  Worker --> Bedrock[Bedrock]
  Worker --> Postgres
  Laravel --> OTel[OTel Collector]
  Worker --> OTel
  OTel --> Prometheus[Prometheus]
  OTel --> Tempo[Tempo]
  Prometheus --> Grafana[Grafana]
  Prometheus --> Alertmanager[Alertmanager]
```

La pipeline documentale usa LocalStack per S3, SQS, Step Functions, SSM, Secrets Manager, EventBridge e SES locale. S3, Textract e Bedrock possono essere instradati verso AWS reale tramite parametri runtime, lasciando il resto dello stack in locale.

## Struttura Repository

- `app/Copilot`: dominio applicativo, servizi AI/OCR/workflow, audit e supporto runtime.
- `app/Http`: controller API, middleware, request validation e risposte HTTP.
- `app/Models/Copilot`: model Eloquent.
- `apps/frontend`: SPA React organizzata per `app`, `components`, `features`, `hooks`, `lib`, `styles`.
- `openapi/v1/alittlebyte-poc-api.yaml`: contratto API versionato, sorgente del client TypeScript generato.
- `infra/localstack`: Terraform per dipendenze AWS-like locali.
- `infra/aws`: area riservata alla baseline AWS reale quando IAM, remote state e ownership saranno definiti.
- `docker`: immagini runtime, Traefik, Nginx, observability e certificati locali.
- `docs`: architettura, runbook, sicurezza, operation guide e diagrammi.

## Avvio Locale

```bash
cp .env.example .env
make setup
```

`make setup` esegue:

- generazione certificato TLS locale;
- build immagini Docker;
- avvio PostgreSQL, Redis e LocalStack;
- `terraform init/apply` su `infra/localstack`;
- migrazioni Laravel;
- avvio app, worker, Nginx, Traefik e servizi di osservabilita.

Endpoint:

- Applicazione HTTP: `http://localhost:8080`
- Applicazione HTTPS locale: `https://localhost:8443`
- Health: `https://localhost:8443/health`
- Readiness: `https://localhost:8443/ready`
- Prometheus: `http://localhost:9090`
- Alertmanager: `http://localhost:9093`
- Grafana: `http://localhost:3000`
- Tempo: `http://localhost:3200`
- LocalStack edge: `http://localhost:4566`

Il certificato TLS locale e self-signed. Firefox o altri browser possono mostrare un warning finche il certificato in `docker/traefik/certs` non viene considerato attendibile dal trust store locale.

## Configurazione

I container ricevono solo il bootstrap necessario a leggere la configurazione runtime:

```env
CONFIG_SOURCE=aws
CONFIG_SSM_PATH=/poc/app
CONFIG_SECRET_IDS=/poc/app/runtime
CONFIG_AWS_ENDPOINT=http://localstack:4566
CONFIG_AWS_REGION=eu-north-1
```

Terraform scrive i parametri applicativi in SSM e i segreti in Secrets Manager. I valori principali si impostano in `.env` prima di `make infra-apply`.

Parametri applicativi:

| Variabile | Uso |
| --- | --- |
| `POC_APP_URL` | URL pubblico usato da Laravel. |
| `POC_DOCUMENT_DISK` | `s3` per LocalStack, `real_s3` per S3 reale. |
| `POC_MAX_UPLOAD_MB` | Limite upload PDF. |
| `POC_MAX_PDF_PAGES` | Limite pagine PDF. |
| `POC_PROCESSING_TIMEOUT_SECONDS` | Timeout massimo pipeline documentale. |
| `POC_CONFIDENCE_THRESHOLD` | Soglia di confidenza per revisione. |
| `POC_IDENTITY_MODE` | Modalita identita locale o header trusted. |

## AWS Reale per S3, Textract e Bedrock

Questa modalita usa un runtime ibrido controllato: LocalStack resta responsabile di SSM, Secrets, SQS e Step Functions; S3, Textract e Bedrock puntano ad AWS reale. Impostare in `.env`:

```env
POC_DOCUMENT_DISK=real_s3

# Regione e bucket usati per gli oggetti sorgente S3.
AWS_REAL_REGION=eu-central-1
AWS_REAL_S3_BUCKET=nome-bucket-documenti
AWS_REAL_S3_PREFIX=documents/

# Credenziali temporanee o access key dedicate allo smoke locale.
AWS_REAL_ACCESS_KEY_ID=
AWS_REAL_SECRET_ACCESS_KEY=
AWS_REAL_SESSION_TOKEN=

# Textract usa la regione OCR/S3.
TEXTRACT_ENABLED=true
TEXTRACT_REGION=eu-central-1
TEXTRACT_MAX_PAGES=50
TEXTRACT_MAX_BYTES=20971520

# Bedrock puo usare una regione diversa.
BEDROCK_REGION=eu-north-1
POC_BEDROCK_MODEL_ID=amazon.nova-lite-v1:0
BEDROCK_ENDPOINT=
```

Poi applicare la configurazione e riavviare lo stack:

```bash
make local-tls
docker compose build
docker compose up -d postgres redis localstack
make infra-apply
make release
docker compose up -d app queue nginx traefik otel-collector prometheus tempo alertmanager grafana
```

Note operative:

- `AWS_REAL_REGION` e `TEXTRACT_REGION` devono rappresentare la regione operativa della coppia S3/Textract.
- `BEDROCK_REGION` e indipendente e deve essere una regione dove il modello scelto e abilitato per l'account.
- Le credenziali reali non vanno committate. Con IAM aziendale, preferire credenziali temporanee/OIDC/role assumption e secret injection gestita.
- La workflow orchestration resta locale finche non viene aggiunto Terraform AWS reale per Step Functions, SQS, SSM e Secrets Manager.

## Observability

Servizi locali:

| Servizio | URL | Uso |
| --- | --- | --- |
| Grafana | `http://localhost:3000` | Dashboard applicative e trace drill-down. |
| Prometheus | `http://localhost:9090` | Metriche e regole di alerting. |
| Alertmanager | `http://localhost:9093` | Routing alert. |
| Tempo | `http://localhost:3200` | Storage trace OTLP. |

Credenziali Grafana di default:

```env
GRAFANA_ADMIN_USER=admin
GRAFANA_ADMIN_PASSWORD=admin
```

Le dashboard sono provisioning-as-code in `docker/grafana/dashboards`. Per modificarle:

1. Aprire Grafana e modificare una dashboard.
2. Esportare il JSON aggiornato.
3. Sostituire il file corrispondente in `docker/grafana/dashboards`.
4. Riavviare Grafana con `docker compose restart grafana`.
5. Validare la configurazione con `make observability-config`.

Prometheus carica regole da `docker/prometheus/rules`; Alertmanager da `docker/alertmanager/alertmanager.yml`; OTel Collector da `docker/otel-collector/config.yml`.

## Comandi Principali

| Comando | Descrizione |
| --- | --- |
| `make setup` | Setup completo locale. |
| `make release` | Esegue le migrazioni applicative. |
| `make logs` | Segue log app, worker, Nginx e LocalStack. |
| `make fresh` | Resetta database e dati generati. |
| `make verify-fast` | Backend, frontend, infra e observability senza audit estesi. |
| `make verify` | Quality gate completo locale. |
| `make frontend-a11y` | Axe e Pa11y sullo stack HTTPS locale. |
| `make aws-smoke` | Smoke opzionale, richiede parametri AWS reali. |

## Test e Quality Gate

Tutti i controlli sono containerizzati.

```bash
make test
make pint
make frontend-typecheck
make frontend-test
make frontend-build
make frontend-audit
make openapi-validate
make observability-config
make verify-fast
make verify
```

CI/CD GitHub Actions:

- `Pest`: test Laravel.
- `Pint`: code style PHP.
- `Frontend`: OpenAPI client generation, TypeScript, Vitest, build e npm audit production.
- `Quality Gates`: Composer validate, Larastan/PHPStan, OpenAPI lint, Terraform validate, observability config.
- `Accessibility`: Axe e Pa11y sullo stack locale.
- `LocalStack Smoke`: Compose, Terraform, migrazioni, health/readiness/API e dashboard locali.
- `Containers`: build immagini runtime, Trivy scan, publish GHCR su branch/tag abilitati.
- `AWS Smoke`: workflow manuale per role OIDC aziendale quando disponibile.

## Sicurezza

- Nessuna interfaccia `/admin` a runtime.
- API JSON versionata e documentata da OpenAPI.
- Configurazione separata tra parametri non sensibili e segreti.
- Upload PDF limitati per dimensione e pagine.
- Worker asincrono con DLQ e idempotenza task-token.
- Metriche interne non esposte attraverso Traefik pubblico.
- Container runtime separati per app, worker, Nginx e tool.
- Scansione immagini con Trivy su vulnerabilita HIGH/CRITICAL.
- Mapping OWASP ASVS e IAM in `docs/security`.

## Riferimenti

- [AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/welcome.html)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [Google SRE: Monitoring Distributed Systems](https://sre.google/sre-book/monitoring-distributed-systems/)
- [OpenTelemetry Collector](https://opentelemetry.io/docs/collector/)
- [Amazon Textract StartDocumentTextDetection](https://docs.aws.amazon.com/textract/latest/APIReference/API_StartDocumentTextDetection.html)
- [Amazon Bedrock model access](https://docs.aws.amazon.com/bedrock/latest/userguide/model-access.html)
