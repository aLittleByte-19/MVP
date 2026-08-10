#!/usr/bin/env bash
# Verifica meccanica della Dependency Rule (ADR 0010): il livello Domain dei
# domini esagonali (Documents, Communications) non deve importare ne'
# referenziare Illuminate\*, Aws\*, o modelli Eloquent (App\Models\*). Le
# violazioni sono cercate su tutto il contenuto del file, non solo sulle
# istruzioni "use", cosi' da intercettare anche i nomi pienamente
# qualificati usati inline.
set -euo pipefail

cd "$(dirname "$0")/../.."

DOMAIN_DIRS=(
  "app/Mvp/Documents/Domain"
  "app/Mvp/Communications/Domain"
)

FORBIDDEN_PATTERNS=(
  'Illuminate\\'
  'Aws\\'
  'App\\Models\\'
)

violations=0

for dir in "${DOMAIN_DIRS[@]}"; do
  if [ ! -d "$dir" ]; then
    echo "::error::Cartella dominio attesa non trovata: $dir"
    violations=1
    continue
  fi

  for pattern in "${FORBIDDEN_PATTERNS[@]}"; do
    matches="$(grep -rnE "$pattern" "$dir" --include='*.php' || true)"

    if [ -n "$matches" ]; then
      echo "Violazione della Dependency Rule in $dir (pattern: $pattern):"
      echo "$matches"
      echo
      violations=1
    fi
  done
done

if [ "$violations" -ne 0 ]; then
  echo "Il livello Domain non puo' dipendere da Illuminate\\*, Aws\\*, o da modelli Eloquent (vedi ADR 0010)." >&2
  exit 1
fi

echo "Dependency Rule rispettata: nessun riferimento a Illuminate\\*, Aws\\*, o App\\Models\\* nel livello Domain."
