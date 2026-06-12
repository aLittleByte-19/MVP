#!/bin/sh
# Genera il certificato TLS self-signed per l'ambiente locale (Traefik).
# La config sta in un file temporaneo invece che in flag -addext, così il
# comando funziona identico su OpenSSL e LibreSSL.
set -eu

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <cert-path> <key-path>" >&2
  exit 1
fi

CERT_PATH="$1"
KEY_PATH="$2"

mkdir -p "$(dirname "$CERT_PATH")" "$(dirname "$KEY_PATH")"

CONFIG="$(mktemp)"
trap 'rm -f "$CONFIG"' EXIT

cat > "$CONFIG" <<'EOF'
[ req ]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[ req_distinguished_name ]
CN = poc.localhost

[ v3_req ]
basicConstraints = CA:FALSE
keyUsage = digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[ alt_names ]
DNS.1 = localhost
DNS.2 = poc.localhost
DNS.3 = traefik
DNS.4 = *.localhost
IP.1 = 127.0.0.1
EOF

openssl req -x509 -newkey rsa:2048 -sha256 -days 365 -nodes \
  -keyout "$KEY_PATH" -out "$CERT_PATH" \
  -config "$CONFIG" -extensions v3_req 2>/dev/null

chmod 600 "$KEY_PATH"

echo "Generated local TLS certificate: $CERT_PATH"
echo "Generated local TLS key: $KEY_PATH"
