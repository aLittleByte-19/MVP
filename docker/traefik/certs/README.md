# Local Traefik Certificates

Local certificate and private key files are runtime artifacts and must not be committed.

Generate them from the repository root with:

```bash
make local-tls
```

The generator writes files through `scripts/tls/generate-local-cert.php`.
