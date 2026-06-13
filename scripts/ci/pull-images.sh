#!/usr/bin/env bash
# Pulla le immagini esterne della CI provando prima il mirror GHCR
# (autenticato con GITHUB_TOKEN: nessun rate limit per-IP), con fallback
# sull'upstream pubblico (anonimo, rate-limitato) con retry.
set -euo pipefail

list_file="${1:?uso: pull-images.sh <file-lista-immagini>}"
script_dir="$(dirname "$0")"

pull_upstream() {
  local ref="$1"

  for attempt in {1..5}; do
    if docker pull --quiet "$ref"; then
      return 0
    fi

    echo "::warning::Pull di ${ref} fallito (tentativo ${attempt}/5), riprovo tra $((attempt * 20))s"
    sleep $((attempt * 20))
  done

  return 1
}

while IFS= read -r image; do
  [ -n "$image" ] || continue

  if mirror="$(bash "$script_dir/mirror-ref.sh" "$image" 2>/dev/null)"; then
    if docker pull --quiet "$mirror" 2>/dev/null; then
      docker tag "$mirror" "$image"
      docker rmi "$mirror" > /dev/null
      echo "${image} <- mirror GHCR"
      continue
    fi

    echo "::warning::Mirror GHCR non disponibile per ${image}, pull dall'upstream"
  fi

  pull_upstream "$image"
  echo "${image} <- upstream"
done < "$list_file"
