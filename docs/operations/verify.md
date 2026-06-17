# Verification Matrix

## Canonical Targets

| Target | Purpose | Real AWS required |
| --- | --- | --- |
| `make verify-fast` | Backend, frontend, infra and observability checks. | No |
| `make verify` | `verify-fast`, OpenAPI validation and frontend dependency audit. | No |
| `make verify-backend` | Composer, route list, Pest, Pint and PHPStan. | No |
| `make verify-frontend` | OpenAPI client generation, TypeScript check, tests and build. | No |
| `make verify-infra` | Compose config and LocalStack Terraform fmt/validate. | No |
| `make verify-observability` | OTel Collector and Prometheus config/rules. | No |
| `make verify-ci-local` | Fast local gate plus OpenAPI lint. | No |
| `make aws-smoke` | Guarded placeholder for real AWS smoke. | Yes |

## Direct Commands

```bash
docker compose run --rm --no-deps app composer validate --strict
docker compose run --rm --no-deps app php artisan test
docker compose run --rm --no-deps app php vendor/bin/pint --test
docker compose run --rm --no-deps app vendor/bin/phpstan analyse --memory-limit=1G
docker compose --profile tools run --rm node npm run frontend:typecheck
docker compose --profile tools run --rm node npm run frontend:test
docker compose --profile tools run --rm node npm run frontend:build
docker compose config --quiet
docker compose --profile tools run --rm terraform fmt -check
docker compose --profile tools run --rm terraform validate
make observability-config
```

Do not run real AWS S3/Textract/Bedrock checks in ordinary CI. Keep them behind `make aws-smoke` or a manual GitHub Actions workflow with OIDC.
