.PHONY: help test fresh logs bash restart setup

# Colori per l'output
BLUE  := \033[34m
RESET := \033[0m

help:
	@echo "$(BLUE)Comandi disponibili per la PoC:$(RESET)"
	@echo "  $(BLUE)make test$(RESET)      Esegue la suite di test (Pest)"
	@echo "  $(BLUE)make fresh$(RESET)     Resetta database e dati generati (documenti e bozze)"
	@echo "  $(BLUE)make logs$(RESET)      Segue i log dei container app e queue"
	@echo "  $(BLUE)make bash$(RESET)      Entra nel container dell'applicazione"
	@echo "  $(BLUE)make restart$(RESET)   Riavvia tutti i servizi Docker"
	@echo "  $(BLUE)make setup$(RESET)     Costruisce l'ambiente da zero"

test:
	docker compose exec -T app ./vendor/bin/pest

fresh:
	docker compose exec app php artisan migrate:fresh --seed
	docker compose exec app php artisan poc:reset-data --force

logs:
	docker compose logs -f app queue

bash:
	docker compose exec app bash

restart:
	docker compose restart

setup:
	docker compose up -d --build
	@echo "$(BLUE)L'ambiente è stato configurato ed è in fase di avvio.$(RESET)"
	@echo "$(BLUE)Puoi monitorare il progresso con: make logs$(RESET)"
