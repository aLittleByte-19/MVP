# Local Production-Like Runbook

## Start

```bash
make setup
```

Il target esegue:

- generazione TLS locale tramite container se cert/key non esistono gia';
- build delle immagini applicative;
- avvio di PostgreSQL, Redis e LocalStack;
- `terraform init` e `terraform apply` dal container Compose `terraform`;
- migrazioni applicative;
- avvio di app, Nginx, worker SQS e Traefik.

![Ambiente locale e provisioning](../architecture/diagrams/02_ambiente_locale_provisioning.drawio.png)

<sub>Sorgente editabile: [`02_ambiente_locale_provisioning.drawio`](../architecture/diagrams/02_ambiente_locale_provisioning.drawio), export [`SVG`](../architecture/diagrams/02_ambiente_locale_provisioning.drawio.svg).</sub>

Endpoint:

- App: https://localhost:8443
- Health: https://localhost:8443/health
- Readiness: https://localhost:8443/ready
- LocalStack edge: http://localhost:4566 (bind solo 127.0.0.1)

## TLS locale trusted

`make local-tls` resta completamente containerizzato e genera un certificato
self-signed per Traefik. E' sufficiente per smoke test e CLI, ma i browser
mostrano l'avviso di identita' non riconosciuta.

Per sopprimere l'avviso in modo pulito, usare una CA locale trusted con
`mkcert` installato sull'host:

```bash
make trusted-local-tls
docker compose restart traefik
```

Il target esegue `mkcert -install`, crea un certificato valido per
`localhost`, `mvp.localhost`, `*.localhost`, `127.0.0.1` e `::1`, e lo scrive
negli stessi file montati da Traefik. Non viene eseguito dentro Docker perche'
deve aggiornare il trust store della macchina locale.

## Terraform

Terraform vive in `infra/localstack` ma viene eseguito solo tramite Docker Compose:

```bash
make infra-up
make infra-init
make infra-plan
make infra-apply
make infra-destroy
```

Il servizio `terraform` usa l'endpoint interno `http://localstack:4566` e crea S3, SQS/DLQ, Step Functions, EventBridge, SES, SSM Parameter Store e Secrets Manager.

## Runtime Configuration

I container applicativi ricevono solo parametri bootstrap `CONFIG_*`. I valori runtime sono caricati da:

- SSM Parameter Store: `/mvp/app`
- Secrets Manager: `/mvp/app/runtime`

Se una chiave obbligatoria manca, il bootstrap Laravel fallisce. Questa scelta evita configurazioni implicite e rende visibili errori di provisioning.

I valori risolti vengono cachati in `bootstrap/cache/runtime-config.php` dentro il container (PHP-FPM rieseguirebbe altrimenti le chiamate SSM/Secrets a ogni richiesta). La cache vive quanto il container: `make refresh-runtime` ricrea `app` e `queue` e quindi la rigenera.

## Reset Completo

```bash
make reset-all          # chiede conferma
make reset-all FORCE=1  # senza conferma
```

Elimina tutti i volumi locali (PostgreSQL, Redis, LocalStack), svuota il prefisso del bucket S3 reale se `AWS_REAL_S3_BUCKET` e' configurato nel `.env`, e riesegue `make setup` da zero. E' distruttivo per design: pensato per riportare la MVP allo stato iniziale.

## Checks

```bash
make test
make pint
make frontend-typecheck
make frontend-test
make frontend-build
make frontend-audit
make frontend-a11y
make verify-fast
make verify
```

La suite imposta `CONFIG_SOURCE=env` per restare indipendente da LocalStack. I test di pipeline usano mock mirati dei servizi AI e non modificano il contratto runtime.

## Real AWS OCR/AI

La configurazione locale standard usa LocalStack S3 e `TEXTRACT_ENABLED=false`. Per validare il percorso critico con S3/Textract reali, impostare esplicitamente:

```bash
MVP_DOCUMENT_DISK=real_s3
AWS_REAL_REGION=...
AWS_REAL_S3_BUCKET=...
AWS_REAL_S3_PREFIX=documents/
TEXTRACT_ENABLED=true
TEXTRACT_REGION=...   # stessa regione del bucket S3
```

Dopo ogni modifica al `.env`, applicare i nuovi valori a SSM/Secrets e ricaricare i processi:

```bash
make refresh-runtime
```

Le credenziali `AWS_REAL_*` sono condivise da S3, Textract e Bedrock e non vanno salvate in repository. Bedrock richiede `BEDROCK_REGION` e `BEDROCK_MODEL_ID` con accesso gia' abilitato nell'account. Per le copertine dell'Assistant imposta `BEDROCK_IMAGE_MODEL_ID` su un modello supportato nel tuo account (default `stability.sd3-5-large-v1:0`; alternative `stability.stable-image-core-v1:0` o `amazon.nova-canvas-v1:0`) e `BEDROCK_IMAGE_REGION` sulla region che lo serve (default `us-west-2`). I modelli immagine sono attivi in region diverse da quelli testo, quindi le copertine usano un client Bedrock dedicato: `BEDROCK_REGION` resta la region del modello testo. Il payload della richiesta viene scelto dal modello configurato, quindi cambiare famiglia non richiede modifiche al codice. Senza modello immagini la generazione del testo funziona normalmente e ogni copertina risulta degradata con motivo esplicito: e' il comportamento atteso, non un errore.

## Worker delle pipeline

Lo stack avvia un worker per pipeline: `queue` consuma la coda documentale, `queue-communications` quella delle comunicazioni. `make workers WORKERS=n` scala entrambi.

```bash
docker compose logs -f queue-communications
docker compose exec app php artisan mvp:dlq:list --queue=communications
```

`COMMUNICATION_PIPELINE_STATE_MACHINE_ARN` e `COMMUNICATION_PIPELINE_TASK_QUEUE_URL` sono chiavi runtime obbligatorie: dopo un aggiornamento del codice che le introduce, esegui `make refresh-runtime` prima di avviare lo stack, altrimenti il boot si interrompe con l'elenco delle chiavi mancanti.
