# NEXUM Document Intelligence

Applicazione Laravel per generazione assistita di comunicazioni HR e analisi di PDF multi-destinatario. Il runtime locale usa Docker Compose, LocalStack e Terraform containerizzato per avvicinare l'ambiente di sviluppo a un modello di produzione ripetibile.

## Runtime

- PHP-FPM e Nginx sono immagini immutabili: codice e dipendenze sono inclusi in build.
- Terraform gira nel servizio Compose `terraform` e provisiona LocalStack.
- I valori non sensibili sono in SSM Parameter Store sotto `/nexum/poc/app`.
- I segreti runtime sono in Secrets Manager nel secret `/nexum/poc/app/runtime`.
- Laravel carica la configurazione da SSM e Secrets Manager prima del bootstrap framework.
- Storage documentale e code usano S3 e SQS su LocalStack.
- La pipeline documentale non applica sostituzioni automatiche: errori Bedrock, split vuoti o estrazioni fallite producono stato `failed` esplicito.

## Avvio Locale

```bash
make setup
```

Il comando esegue build delle immagini, avvia PostgreSQL/Redis/LocalStack, inizializza e applica Terraform, lancia le migrazioni e avvia app, Nginx e worker SQS.

Comandi equivalenti passo-passo:

```bash
make infra-up
make infra-apply
make release
docker compose up -d app nginx queue
```

Endpoint locali:

- Applicazione: http://localhost:8080
- Console operativa: http://localhost:8080/admin
- LocalStack edge: http://localhost:4566

## Configurazione Runtime

La configurazione applicativa non viene passata ai container come environment diretti. Compose fornisce solo i valori bootstrap necessari a leggere LocalStack:

- `CONFIG_SOURCE=aws`
- `CONFIG_SSM_PATH=/nexum/poc/app`
- `CONFIG_SECRET_IDS=/nexum/poc/app/runtime`
- `CONFIG_AWS_ENDPOINT=http://localstack:4566`
- `CONFIG_AWS_REGION=eu-north-1`

Terraform crea e aggiorna:

- bucket S3 documentale;
- coda SQS e DLQ;
- EventBridge bus/rule;
- Step Functions state machine;
- identita SES locale;
- parametri SSM applicativi;
- secret runtime con `APP_KEY`, password database e credenziali SDK LocalStack.

Valori bootstrap e porte possono essere sovrascritti con variabili shell usando i nomi presenti in `.env.example`; i valori runtime dell'applicazione restano in SSM/Secrets.

## Terraform LocalStack

Terraform non richiede installazione host. I target Make usano `docker compose --profile tools run --rm terraform`.

```bash
make infra-init
make infra-plan
make infra-apply
make infra-destroy
```

Lo stato Terraform locale resta sotto `infra/local`; la cache plugin è in un volume Docker dedicato.

## Test E Qualita

```bash
make test
make pint
```

I test impostano `CONFIG_SOURCE=env` e usano driver isolati, quindi non richiedono LocalStack. I mock Bedrock sono ammessi solo nei test unitari/feature isolati; il flusso applicativo principale fallisce esplicitamente quando un servizio richiesto non risponde correttamente.

## Operazioni

```bash
make logs
make fresh
make release
docker compose down
```

- `make logs` segue app, worker, Nginx e LocalStack.
- `make fresh` ricrea il database e cancella i dati applicativi generati.
- `make release` esegue le migrazioni come job esplicito.
