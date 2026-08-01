# Local Traefik Certificates

Local certificate and private key files are runtime artifacts and must not be committed.

Generate them from the repository root with:

```bash
make local-tls
```

The generator writes files through `scripts/tls/generate-local-cert.sh`.

That default target creates a self-signed certificate, so browsers may still
warn unless the certificate is manually trusted.

For a browser-trusted local certificate, install `mkcert` on the host and run:

```bash
make trusted-local-tls
```

The trusted target installs/uses the local mkcert CA on the host and writes the
same `mvp-local.test.crt` / `mvp-local.test.key` files consumed by Traefik.
