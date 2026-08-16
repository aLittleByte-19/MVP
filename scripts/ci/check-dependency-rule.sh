#!/usr/bin/env bash
# Verifica meccanica della Dependency Rule (ADR 0010): il livello Domain dei
# domini esagonali (Documents, Communications) non deve importare ne'
# referenziare Illuminate\*, Aws\*, o modelli Eloquent (App\Models\*), ne'
# il namespace dell'altro dominio (i due domini non si conoscono a vicenda:
# solo Support/Identity/Workflow/Audit/Observability sono infrastruttura
# condivisa legittima). Le violazioni sono cercate su tutto il contenuto del
# file, non solo sulle istruzioni "use", cosi' da intercettare anche i nomi
# pienamente qualificati usati inline.
#
# Il livello Application ha un vincolo piu' permissivo, non lo stesso di
# Domain: puo' usare Illuminate\* (es. Illuminate\Support\Str per
# trasformazioni pure, gia' cosi' per scelta esplicita), ma mai un model
# Eloquent o l'SDK AWS diretto ("orchestra esclusivamente attraverso le
# porte", vedi Decision) ne' il namespace dell'altro dominio.
set -euo pipefail

cd "$(dirname "$0")/../.."

COMMON_FORBIDDEN_PATTERNS=(
  'Illuminate\\'
  'Aws\\'
  'App\\Models\\'
)

violations=0

check_dir() {
  local dir="$1"
  shift
  local patterns=("$@")

  if [ ! -d "$dir" ]; then
    echo "::error::Cartella dominio attesa non trovata: $dir"
    violations=1
    return
  fi

  for pattern in "${patterns[@]}"; do
    matches="$(grep -rnE "$pattern" "$dir" --include='*.php' || true)"

    if [ -n "$matches" ]; then
      echo "Violazione della Dependency Rule in $dir (pattern: $pattern):"
      echo "$matches"
      echo
      violations=1
    fi
  done
}

check_dir "app/Mvp/Documents/Domain" "${COMMON_FORBIDDEN_PATTERNS[@]}" 'App\\Mvp\\Communications\\'
check_dir "app/Mvp/Communications/Domain" "${COMMON_FORBIDDEN_PATTERNS[@]}" 'App\\Mvp\\Documents\\'

APPLICATION_FORBIDDEN_PATTERNS=(
  'Aws\\'
  'App\\Models\\'
)

check_dir "app/Mvp/Documents/Application" "${APPLICATION_FORBIDDEN_PATTERNS[@]}" 'App\\Mvp\\Communications\\'
check_dir "app/Mvp/Communications/Application" "${APPLICATION_FORBIDDEN_PATTERNS[@]}" 'App\\Mvp\\Documents\\'

if [ "$violations" -ne 0 ]; then
  echo "Il livello Domain non puo' dipendere da Illuminate\\*, Aws\\*, modelli Eloquent, ne' dal namespace dell'altro dominio; Application non puo' dipendere da Aws\\*, modelli Eloquent, ne' dal namespace dell'altro dominio (vedi ADR 0010)." >&2
  exit 1
fi

echo "Dependency Rule rispettata: Domain libero da Illuminate\\*/Aws\\*/App\\Models\\*/altro dominio; Application libera da Aws\\*/App\\Models\\*/altro dominio."
