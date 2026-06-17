#!/usr/bin/env bash
# Lista (ordinata e deduplicata) di tutte le immagini esterne usate dalla CI:
# i servizi docker-compose pullati dalla pipeline + le immagini base dei
# Dockerfile runtime. Unica fonte di verità per cache e mirror GHCR.
set -euo pipefail

cd "$(dirname "$0")/../.."

services=(node frontend-audit terraform postgres redis localstack traefik otel-collector prometheus tempo alertmanager grafana loki alloy)

compose_json="$(docker compose --profile tools config --format json)"

{
  for svc in "${services[@]}"; do
    # -e: fallisce se il servizio non esiste o non ha un'immagine
    jq -er --arg svc "$svc" '.services[$svc].image' <<< "$compose_json"
  done

  grep -hE '^FROM ' docker/php/Dockerfile docker/nginx/Dockerfile | awk '{print $2}'
} | sort -u
