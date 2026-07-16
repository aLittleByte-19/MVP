.PHONY: help test pint node-install frontend-build frontend-lint frontend-test frontend-typecheck frontend-audit frontend-a11y frontend-s3-local-provision frontend-s3-local-upload frontend-s3-local-deploy edge-cdn-local-url frontend-serving-local-test openapi-generate openapi-validate observability-config observability-up local-tls trusted-local-tls fresh logs sh restart setup release infra-up infra-init infra-plan infra-apply infra-destroy refresh-runtime verify verify-fast verify-backend verify-frontend verify-infra verify-observability verify-ci-local aws-smoke reset-all workers backup-local restore-local

# Colori per l'output
BLUE  := \033[34m
RESET := \033[0m
LOCALSTACK_ENDPOINT_INTERNAL ?= http://localstack:4566
FRONTEND_DIST ?= apps/frontend/dist
FRONTEND_STATIC_BUCKET ?= mvp-frontend-static-local
EDGE_CDN_LOCAL_URL ?= https://localhost:8443
# -T: niente pseudo-TTY per i tool non interattivi. Senza, "docker compose run"
# mette il terminale in raw mode e, se il run si blocca (es. glitch del daemon),
# Ctrl+C viene inghiottito e l'unico modo è chiudere il terminale — che però NON
# uccide il processo "compose run", lasciando container *-run-* orfani in stato
# "created". Con -T il SIGINT arriva e --rm ripulisce il container.
TERRAFORM := docker compose --profile tools run --rm -T terraform
NODE := docker compose --profile tools run --rm -T node
AWS_CLI := docker compose --profile tools run --rm -T --entrypoint aws aws-cli --endpoint-url=$(LOCALSTACK_ENDPOINT_INTERNAL)
FRONTEND_AUDIT := docker compose --profile tools run --rm -T frontend-audit
TLS_TOOL := docker compose --profile tools run --rm -T tls-tool
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
	@echo "  $(BLUE)make frontend-build$(RESET) Compila la SPA Angular"
	@echo "  $(BLUE)make frontend-lint$(RESET)  Esegue ESLint sul frontend Angular"
	@echo "  $(BLUE)make frontend-test$(RESET)  Esegue i test frontend"
	@echo "  $(BLUE)make frontend-typecheck$(RESET) Esegue typecheck TypeScript"
	@echo "  $(BLUE)make frontend-audit$(RESET) Audit npm production dependencies"
	@echo "  $(BLUE)make frontend-a11y$(RESET)  Esegue axe e Pa11y sullo stack HTTPS locale"
	@echo "  $(BLUE)make frontend-s3-local-provision$(RESET) Provisiona il bucket S3 locale della SPA"
	@echo "  $(BLUE)make frontend-s3-local-deploy$(RESET) Builda e carica la SPA Angular su S3 LocalStack"
	@echo "  $(BLUE)make edge-cdn-local-url$(RESET) Stampa URL della CDN/edge locale"
	@echo "  $(BLUE)make frontend-serving-local-test$(RESET) Smoke test del serving S3 locale + CDN/edge locale"
	@echo "  $(BLUE)make openapi-generate$(RESET) Rigenera il client TypeScript"
	@echo "  $(BLUE)make openapi-validate$(RESET) Valida il contratto OpenAPI"
	@echo "  $(BLUE)make observability-config$(RESET) Valida la configurazione OTel Collector"
	@echo "  $(BLUE)make observability-up$(RESET) Avvia OTel Collector e Prometheus"
	@echo "  $(BLUE)make local-tls$(RESET) Genera il certificato TLS locale per Traefik"
	@echo "  $(BLUE)make trusted-local-tls$(RESET) Genera un certificato locale trusted via mkcert"
	@echo "  $(BLUE)make fresh$(RESET)     Resetta database, Redis (sessioni/cache/rate limit) e dati generati"
	@echo "  $(BLUE)make logs$(RESET)      Segue i log dei container app e queue"
	@echo "  $(BLUE)make sh$(RESET)        Apre una shell nel container applicativo"
	@echo "  $(BLUE)make restart$(RESET)   Riavvia tutti i servizi Docker"
	@echo "  $(BLUE)make workers$(RESET)   Scala i worker della pipeline (WORKERS=n, default 2)"
	@echo "  $(BLUE)make backup-local$(RESET) Crea un dump PostgreSQL locale in backups/local"
	@echo "  $(BLUE)make restore-local BACKUP=...$(RESET) Ripristina un dump PostgreSQL locale"
	@echo "  $(BLUE)make setup$(RESET)     Build, LocalStack, Terraform, migrazioni e avvio processi"
	@echo "  $(BLUE)make release$(RESET)   Esegue il job di migrazione applicativa"
	@echo "  $(BLUE)make infra-up$(RESET)      Avvia LocalStack, PostgreSQL e Redis"
	@echo "  $(BLUE)make infra-init$(RESET)    Inizializza Terraform per LocalStack"
	@echo "  $(BLUE)make infra-plan$(RESET)    Pianifica le risorse LocalStack"
	@echo "  $(BLUE)make infra-apply$(RESET)   Applica le risorse LocalStack"
	@echo "  $(BLUE)make infra-destroy$(RESET) Distrugge le risorse LocalStack"
	@echo "  $(BLUE)make refresh-runtime$(RESET) Riapplica SSM/Secrets e ricarica app+queue (dopo modifiche al .env)"
	@echo "  $(BLUE)make verify-fast$(RESET)   Esegue i controlli locali rapidi"
	@echo "  $(BLUE)make verify$(RESET)        Esegue la batteria completa locale"
	@echo "  $(BLUE)make aws-smoke$(RESET)     Smoke opzionale su AWS reale, richiede credenziali"
	@echo "  $(BLUE)make reset-all$(RESET)     Reset TOTALE: volumi locali + S3 reale, poi setup da zero (FORCE=1 senza conferma)"

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
	$(NODE) npm run frontend:lint
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

frontend-lint: node-install
	$(NODE) npm run frontend:lint

frontend-test: openapi-generate
	$(NODE) npm run frontend:test

frontend-typecheck: openapi-generate
	$(NODE) npm run frontend:typecheck

frontend-s3-local-provision: infra-apply

frontend-s3-local-upload:
	test -f "$(FRONTEND_DIST)/index.html"
	$(AWS_CLI) s3api head-bucket --bucket "$(FRONTEND_STATIC_BUCKET)"
	$(AWS_CLI) s3 sync "$(FRONTEND_DIST)/" "s3://$(FRONTEND_STATIC_BUCKET)/" --delete --exclude "index.html" --cache-control "public, max-age=3600"
	$(AWS_CLI) s3 cp "$(FRONTEND_DIST)/" "s3://$(FRONTEND_STATIC_BUCKET)/" --recursive --exclude "*" --include "*.js" --include "*.css" --cache-control "public, max-age=31536000, immutable"
	$(AWS_CLI) s3 cp "$(FRONTEND_DIST)/index.html" "s3://$(FRONTEND_STATIC_BUCKET)/index.html" --cache-control "no-cache, max-age=0, must-revalidate" --content-type "text/html; charset=utf-8"

frontend-s3-local-deploy: frontend-build frontend-s3-local-provision
	$(MAKE) frontend-s3-local-upload

edge-cdn-local-url:
	@printf "%s\n" "$(EDGE_CDN_LOCAL_URL)"
	@printf "\n"

frontend-serving-local-test: frontend-s3-local-deploy
	@if [ ! -f docker/traefik/certs/mvp-local.test.crt ] || [ ! -f docker/traefik/certs/mvp-local.test.key ]; then $(MAKE) local-tls; fi
	docker compose up -d --wait --force-recreate edge-cdn traefik
	@url="$(EDGE_CDN_LOCAL_URL)"; \
	echo "$(BLUE)Testing $$url$(RESET)"; \
	curl -kfsS "$$url" | grep -q "<mvp-root"

frontend-audit: node-install
	$(NODE) npm audit --omit=dev --audit-level=high

frontend-a11y: frontend-s3-local-deploy
	@if [ ! -f docker/traefik/certs/mvp-local.test.crt ] || [ ! -f docker/traefik/certs/mvp-local.test.key ]; then $(MAKE) local-tls; fi
	docker compose up -d --wait --force-recreate app nginx edge-cdn traefik
	$(FRONTEND_AUDIT) node scripts/a11y/csp-smoke.mjs https://traefik:8443
	$(FRONTEND_AUDIT) node scripts/a11y/axe-playwright.mjs https://traefik:8443
	$(FRONTEND_AUDIT) node scripts/a11y/pa11y-runner.mjs https://traefik:8443

openapi-generate: node-install
	$(NODE) npm run openapi:generate

openapi-validate: node-install
	$(NODE) npx --yes @redocly/cli@latest lint openapi/v1/alittlebyte-mvp-api.yaml

observability-config:
	docker compose run --rm --no-deps otel-collector validate --config=/etc/otelcol-contrib/config.yml
	docker compose run --rm --no-deps --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml

observability-up:
	docker compose up -d otel-collector prometheus tempo alertmanager grafana loki alloy

pint:
	docker compose build app
	docker compose run --rm --no-deps app php vendor/bin/pint --test

local-tls:
	$(TLS_TOOL) scripts/tls/generate-local-cert.sh docker/traefik/certs/mvp-local.test.crt docker/traefik/certs/mvp-local.test.key

trusted-local-tls:
	scripts/tls/generate-trusted-local-cert.sh docker/traefik/certs/mvp-local.test.crt docker/traefik/certs/mvp-local.test.key

fresh:
	docker compose --profile release run --rm migrate php artisan migrate:fresh --seed --force
	docker compose run --rm app php artisan mvp:reset-data --force
	docker compose up -d redis
	docker compose exec -T redis redis-cli FLUSHALL

logs:
	docker compose logs -f app queue nginx edge-cdn localstack

sh:
	docker compose run --rm app sh

restart:
	docker compose restart

# Scala i worker della pipeline documentale (default 2): il servizio queue non
# ha container_name fisso e l'idempotenza dei task e' garantita da
# task_token_hash + claim atomico, quindi piu' repliche sono sicure.
WORKERS ?= 2
workers:
	docker compose up -d --no-recreate --scale queue=$(WORKERS) queue

# --clean --if-exists: il dump droppa e ricrea gli oggetti, cosi' il restore
# funziona anche su un database gia' migrato senza errori di oggetti duplicati.
backup-local:
	@mkdir -p backups/local
	docker compose exec -T postgres sh -lc 'pg_dump --clean --if-exists -U "$$POSTGRES_USER" "$$POSTGRES_DB"' > backups/local/mvp-$$(date +%Y%m%d-%H%M%S).sql
	@echo "$(BLUE)Backup creato in backups/local.$(RESET)"

restore-local:
	@test -n "$(BACKUP)" || { echo "BACKUP=path/al/dump.sql obbligatorio"; exit 1; }
	@test -f "$(BACKUP)" || { echo "Dump non trovato: $(BACKUP)"; exit 1; }
	docker compose exec -T postgres sh -lc 'psql -v ON_ERROR_STOP=1 -U "$$POSTGRES_USER" "$$POSTGRES_DB"' < "$(BACKUP)"

setup:
	@if [ ! -f docker/traefik/certs/mvp-local.test.crt ] || [ ! -f docker/traefik/certs/mvp-local.test.key ]; then $(MAKE) local-tls; else echo "$(BLUE)Certificato TLS locale gia' presente.$(RESET)"; fi
	docker compose --profile release build
	docker compose up -d postgres redis localstack
	$(MAKE) infra-apply
	$(MAKE) frontend-build
	$(MAKE) frontend-s3-local-upload
	$(MAKE) release
	docker compose up -d app nginx queue traefik otel-collector prometheus tempo alertmanager grafana loki alloy
	@echo "$(BLUE)L'ambiente è stato configurato ed è in fase di avvio.$(RESET)"
	@echo "$(BLUE)Endpoint locale: https://localhost:8443$(RESET)"
	@echo "$(BLUE)Grafana: https://grafana.localhost:8443$(RESET)"
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

# Riscrive SSM/Secrets Manager con i valori correnti del .env (es. dopo aver
# aggiornato le credenziali AWS_REAL_*) e ricrea app e queue per ricaricare la
# configurazione runtime caricata dal bootstrap Laravel.
refresh-runtime: infra-apply
	docker compose up -d --no-deps --force-recreate app queue
	@echo "$(BLUE)Runtime aggiornato: SSM/Secrets riscritti, app e queue ricreati.$(RESET)"

# Reset TOTALE della MVP: ferma lo stack, elimina tutti i volumi locali
# (PostgreSQL, Redis, LocalStack, osservabilita'), svuota il prefisso del
# bucket S3 reale se configurato nel .env e riparte da zero con make setup.
# Distruttivo per design (e' una MVP): FORCE=1 salta la conferma interattiva.
reset-all:
	@if [ "$(FORCE)" != "1" ]; then \
		printf "Verranno eliminati TUTTI i dati locali e gli oggetti del bucket S3 reale. Continuare? [y/N] "; \
		read answer; case "$$answer" in [yY]) ;; *) echo "Annullato."; exit 1;; esac; \
	fi
	@get() { sed -n "s/^$$1=//p" .env 2>/dev/null | head -1 | sed 's/[[:space:]]*#.*$$//;s/[[:space:]]*$$//'; }; \
	bucket="$$(get AWS_REAL_S3_BUCKET)"; \
	if [ -n "$$bucket" ] && [ "$$bucket" != "not-configured" ]; then \
		prefix="$$(get AWS_REAL_S3_PREFIX)"; prefix="$${prefix:-documents/}"; \
		region="$$(get AWS_REAL_REGION)"; region="$${region:-eu-central-1}"; \
		token="$$(get AWS_REAL_SESSION_TOKEN)"; \
		echo "$(BLUE)Svuoto s3://$$bucket/$$prefix ($$region)$(RESET)"; \
		docker run --rm \
			-e AWS_ACCESS_KEY_ID="$$(get AWS_REAL_ACCESS_KEY_ID)" \
			-e AWS_SECRET_ACCESS_KEY="$$(get AWS_REAL_SECRET_ACCESS_KEY)" \
			$${token:+-e AWS_SESSION_TOKEN="$$token"} \
			-e AWS_DEFAULT_REGION="$$region" \
			public.ecr.aws/aws-cli/aws-cli s3 rm "s3://$$bucket/$${prefix%/}" --recursive; \
	else \
		echo "$(BLUE)S3 reale non configurato nel .env: salto la pulizia remota.$(RESET)"; \
	fi
	docker compose --profile tools --profile release down --volumes --remove-orphans
	$(MAKE) setup
	@echo "$(BLUE)MVP ripartita da zero: dati locali e remoti azzerati.$(RESET)"

# Legge i valori dal .env (gestendo i commenti inline) anziche' dalla shell:
# docker compose carica .env da solo, ma make no, quindi li estraiamo qui.
aws-smoke:
	@get() { sed -n "s/^$$1=//p" .env 2>/dev/null | head -1 | sed 's/[[:space:]]*#.*$$//;s/[[:space:]]*$$//'; }; \
	test -n "$$(get AWS_REAL_REGION)"   || { echo "AWS_REAL_REGION obbligatoria nel .env"; exit 1; }; \
	test -n "$$(get AWS_REAL_S3_BUCKET)" || { echo "AWS_REAL_S3_BUCKET obbligatoria nel .env"; exit 1; }; \
	test -n "$$(get BEDROCK_MODEL_ID)"  || { echo "BEDROCK_MODEL_ID obbligatorio nel .env"; exit 1; }; \
	test "$$(get TEXTRACT_ENABLED)" = "true" || { echo "TEXTRACT_ENABLED=true obbligatorio nel .env"; exit 1; }; \
	docker compose run --rm --no-deps app php artisan about --only=environment
