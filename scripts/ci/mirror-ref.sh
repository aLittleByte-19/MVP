#!/usr/bin/env bash
# Stampa il riferimento mirror GHCR per un'immagine esterna.
# Exit 1 se l'immagine è già su GHCR (non va mirrorata).
set -euo pipefail

ref="${1:?uso: mirror-ref.sh <image-ref>}"
owner="$(tr '[:upper:]' '[:lower:]' <<< "${GITHUB_REPOSITORY_OWNER:?GITHUB_REPOSITORY_OWNER non impostata}")"

tag="${ref##*:}"
path="${ref%:*}"

case "$path" in
  ghcr.io/*) exit 1 ;;
  public.ecr.aws/*) path="${path#public.ecr.aws/}" ;;
  mcr.microsoft.com/*) path="${path#mcr.microsoft.com/}" ;;
  */*) ;;                      # Docker Hub org/repo (es. grafana/loki)
  *) path="library/${path}" ;; # Docker Hub ufficiale (es. traefik)
esac

echo "ghcr.io/${owner}/mirror/${path}:${tag}"
