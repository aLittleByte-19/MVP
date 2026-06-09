.PHONY: help test pint node-install frontend-build frontend-test frontend-typecheck frontend-audit frontend-a11y openapi-generate openapi-validate observability-config observability-up local-tls fresh logs sh restart setup release infra-up infra-init infra-plan infra-apply infra-destroy verify verify-fast verify-backend verify-frontend verify-infra verify-observability verify-ci-local aws-smoke

# Colori per l'output
BLUE  := \033[34m
RESET := \033[0m
TERRAFORM := docker compose --profile tools run --rm terraform
NODE := docker compose --profile tools run --rm node
FRONTEND_AUDIT := docker compose --profile tools run --rm frontend-audit
TLS_TOOL := docker compose --profile tools run --rm tls-tool
TEST_ENV := -e CONFIG_SOURCE=env \
	-e APP_ENV=testing \
	-e CACHE_STORE=array \
	-e SESSION_DRIVER=array \
	-e QUEUE_CONNECTION=sync \
	-e DB_CONNECTION=sqlite \
	-e DB_DATABASE=:memory: \
	-e DB_HOST= \
	-e DB_PORT= \
	-e DB_USERNAME= \
	-e DB_PASSWORD=

help:
	@echo "$(BLUE)Comandi disponibili:$(RESET)"
	@echo "  $(BLUE)make test$(RESET)      Esegue la suite di test (Pest)"
	@echo "  $(BLUE)make pint$(RESET)      Esegue Laravel Pint in modalita' check"
	@echo "  $(BLUE)make frontend-build$(RESET) Compila la SPA React/Vite"
	@echo "  $(BLUE)make frontend-test$(RESET)  Esegue i test frontend"
	@echo "  $(BLUE)make frontend-typecheck$(RESET) Esegue typecheck TypeScript"
	@echo "  $(BLUE)make frontend-audit$(RESET) Audit npm production dependencies"
	@echo "  $(BLUE)make frontend-a11y$(RESET)  Esegue axe e Pa11y sullo stack HTTPS locale"
	@echo "  $(BLUE)make openapi-generate$(RESET) Rigenera il client TypeScript"
	@echo "  $(BLUE)make openapi-validate$(RESET) Valida il contratto OpenAPI"
	@echo "  $(BLUE)make observability-config$(RESET) Valida la configurazione OTel Collector"
	@echo "  $(BLUE)make observability-up$(RESET) Avvia OTel Collector e Prometheus"
	@echo "  $(BLUE)make local-tls$(RESET) Genera il certificato TLS locale per Traefik"
	@echo "  $(BLUE)make fresh$(RESET)     Resetta database e dati generati (documenti e bozze)"
	@echo "  $(BLUE)make logs$(RESET)      Segue i log dei container app e queue"
	@echo "  $(BLUE)make sh$(RESET)        Apre una shell nel container applicativo"
	@echo "  $(BLUE)make restart$(RESET)   Riavvia tutti i servizi Docker"
	@echo "  $(BLUE)make setup$(RESET)     Build, LocalStack, Terraform, migrazioni e avvio processi"
	@echo "  $(BLUE)make release$(RESET)   Esegue il job di migrazione applicativa"
	@echo "  $(BLUE)make infra-up$(RESET)      Avvia LocalStack, PostgreSQL e Redis"
	@echo "  $(BLUE)make infra-init$(RESET)    Inizializza Terraform per LocalStack"
	@echo "  $(BLUE)make infra-plan$(RESET)    Pianifica le risorse LocalStack"
	@echo "  $(BLUE)make infra-apply$(RESET)   Applica le risorse LocalStack"
	@echo "  $(BLUE)make infra-destroy$(RESET) Distrugge le risorse LocalStack"
	@echo "  $(BLUE)make verify-fast$(RESET)   Esegue i controlli locali rapidi"
	@echo "  $(BLUE)make verify$(RESET)        Esegue la batteria completa locale"
	@echo "  $(BLUE)make aws-smoke$(RESET)     Smoke opzionale su AWS reale, richiede credenziali"

# Quality gate rapido: usa solo container e non richiede credenziali AWS reali.
verify-fast: verify-backend verify-frontend verify-infra verify-observability

# Quality gate completo locale: include contratto OpenAPI e audit dipendenze frontend.
verify: verify-fast openapi-validate frontend-audit

verify-backend:
	docker compose build app
	docker compose run --rm --no-deps app composer validate --strict
	docker compose run --rm --no-deps $(TEST_ENV) app php artisan route:list
	docker compose run --rm --no-deps $(TEST_ENV) app php artisan test
	docker compose run --rm --no-deps app php vendor/bin/pint --test
	docker compose run --rm --no-deps $(TEST_ENV) app sh -lc 'if [ -x vendor/bin/phpstan ]; then vendor/bin/phpstan analyse --memory-limit=1G; else echo "phpstan non installato in vendor: skip locale"; fi'

verify-frontend: node-install
	$(NODE) npm run openapi:generate
	$(NODE) npm run frontend:typecheck
	$(NODE) npm run frontend:test
	$(NODE) npm run frontend:build

verify-infra:
	docker compose config --quiet
	$(TERRAFORM) fmt -check
	$(TERRAFORM) init -backend=false
	$(TERRAFORM) validate

verify-observability: observability-config

verify-ci-local: verify-fast openapi-validate

test:
	docker compose build app
	docker compose run --rm --no-deps $(TEST_ENV) app php artisan test

node-install:
	$(NODE) npm ci --ignore-scripts

frontend-build: node-install
	$(NODE) npm run openapi:generate
	$(NODE) npm run frontend:build

frontend-test: openapi-generate
	$(NODE) npm run frontend:test

frontend-typecheck: openapi-generate
	$(NODE) npm run frontend:typecheck

frontend-audit: node-install
	$(NODE) npm audit --omit=dev --audit-level=high

frontend-a11y: node-install
	$(FRONTEND_AUDIT) node scripts/a11y/axe-playwright.mjs https://traefik:8443
	$(FRONTEND_AUDIT) node scripts/a11y/pa11y-runner.mjs https://traefik:8443

openapi-generate: node-install
	$(NODE) npm run openapi:generate

openapi-validate: node-install
	$(NODE) npx --yes @redocly/cli@latest lint openapi/v1/alittlebyte-poc-api.yaml

observability-config:
	docker compose run --rm --no-deps otel-collector validate --config=/etc/otelcol-contrib/config.yml
	docker compose run --rm --no-deps --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml

observability-up:
	docker compose up -d otel-collector prometheus tempo alertmanager grafana

pint:
	docker compose build app
	docker compose run --rm --no-deps app php vendor/bin/pint --test

local-tls:
	$(TLS_TOOL) php scripts/tls/generate-local-cert.php docker/traefik/certs/poc-local.test.crt docker/traefik/certs/poc-local.test.key

fresh:
	docker compose --profile release run --rm migrate php artisan migrate:fresh --seed --force
	docker compose run --rm app php artisan poc:reset-data --force

logs:
	docker compose logs -f app queue nginx localstack

sh:
	docker compose run --rm app sh

restart:
	docker compose restart

setup:
	$(MAKE) local-tls
	docker compose build
	docker compose up -d postgres redis localstack
	$(MAKE) infra-apply
	$(MAKE) release
	docker compose up -d app nginx queue traefik otel-collector prometheus tempo alertmanager grafana
	@echo "$(BLUE)L'ambiente è stato configurato ed è in fase di avvio.$(RESET)"
	@echo "$(BLUE)Endpoint locale: https://localhost:8443$(RESET)"
	@echo "$(BLUE)Grafana: http://localhost:3000$(RESET)"
	@echo "$(BLUE)Puoi monitorare il progresso con: make logs$(RESET)"

release:
	docker compose --profile release run --rm migrate

infra-up:
	docker compose up -d postgres redis localstack

infra-init:
	docker compose up -d localstack
	$(TERRAFORM) init

infra-plan: infra-init
	$(TERRAFORM) plan

infra-apply: infra-init
	$(TERRAFORM) apply -auto-approve

infra-destroy: infra-init
	$(TERRAFORM) destroy -auto-approve

aws-smoke:
	@test -n "$$AWS_REAL_REGION" || (echo "AWS_REAL_REGION obbligatoria" && exit 1)
	@test -n "$$AWS_REAL_S3_BUCKET" || (echo "AWS_REAL_S3_BUCKET obbligatoria" && exit 1)
	@test -n "$$BEDROCK_MODEL_ID" || (echo "BEDROCK_MODEL_ID obbligatorio" && exit 1)
	@test "$$TEXTRACT_ENABLED" = "true" || (echo "TEXTRACT_ENABLED=true obbligatorio per aws-smoke" && exit 1)
	docker compose run --rm --no-deps app php artisan about --only=environment
