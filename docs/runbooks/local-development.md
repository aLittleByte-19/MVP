# Local Development Runbook

## Start

```bash
make setup
```

Il target esegue:

- build delle immagini applicative;
- avvio di PostgreSQL, Redis e LocalStack;
- `terraform init` e `terraform apply` dal container Compose `terraform`;
- migrazioni applicative;
- avvio di app, Nginx e worker SQS.

Endpoint:

- App: http://localhost:8080
- Console operativa: http://localhost:8080/admin
- LocalStack edge: http://localhost:4566

## Terraform

Terraform vive in `infra/local` ma viene eseguito solo tramite Docker Compose:

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

- SSM Parameter Store: `/nexum/poc/app`
- Secrets Manager: `/nexum/poc/app/runtime`

Se una chiave obbligatoria manca, il bootstrap Laravel fallisce. Questa scelta evita configurazioni implicite e rende visibili errori di provisioning.

## Checks

```bash
make test
make pint
```

La suite imposta `CONFIG_SOURCE=env` per restare indipendente da LocalStack. I test di pipeline usano mock mirati dei servizi AI e non modificano il contratto runtime.

## Operations

```bash
make logs
make fresh
make release
docker compose down
```

`make fresh` ricrea database e dati applicativi. `make release` esegue solo le migrazioni con la configurazione caricata da SSM/Secrets.
