#!/bin/sh
# Genera un certificato locale firmato da una CA installata nel trust store
# della macchina host tramite mkcert. Questo script va eseguito sull'host,
# non dentro un container, perche' il trust store da aggiornare e' locale.
set -eu

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <cert-path> <key-path>" >&2
  exit 1
fi

if ! command -v mkcert >/dev/null 2>&1; then
  echo "mkcert non trovato. Installa mkcert sull'host e riesegui il target." >&2
  echo "Su macOS, ad esempio: brew install mkcert nss" >&2
  exit 127
fi

CERT_PATH="$1"
KEY_PATH="$2"

mkdir -p "$(dirname "$CERT_PATH")" "$(dirname "$KEY_PATH")"

mkcert -install
mkcert \
  -cert-file "$CERT_PATH" \
  -key-file "$KEY_PATH" \
  localhost \
  poc.localhost \
  "*.localhost" \
  127.0.0.1 \
  ::1

chmod 600 "$KEY_PATH"

echo "Generated trusted local TLS certificate: $CERT_PATH"
echo "Generated trusted local TLS key: $KEY_PATH"
