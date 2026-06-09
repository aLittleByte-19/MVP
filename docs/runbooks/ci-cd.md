# CI/CD Runbook

## Current CI

Ordinary CI is designed to run without enterprise IAM credentials.

- `.github/workflows/pest.yml` builds the app image and runs Pest through Docker Compose.
- `.github/workflows/pint.yml` runs Pint through Docker Compose.
- `.github/workflows/frontend.yml` installs Node dependencies, checks generated OpenAPI client drift, typechecks, tests, and builds the SPA through Docker Compose.
- `.github/workflows/accessibility.yml` starts the production-like stack and runs axe/Pa11y against the HTTPS SPA.
- `.github/workflows/containers.yml` builds application images, scans them with Trivy, and can publish to GHCR with `GITHUB_TOKEN`.
- `.github/workflows/quality.yml` runs Composer validation, Larastan/PHPStan, OpenAPI lint/client drift, Terraform validate and observability config validation.
- `.github/workflows/localstack-smoke.yml` provisions LocalStack with Terraform and smoke-tests the local stack, including the observability services.
- `.github/workflows/aws-smoke.yml` is manual/conditional and requires a provided AWS role ARN.

## Required Gates Before Deployment

- Backend tests and formatting.
- Frontend typecheck, tests, OpenAPI client drift check, and production build.
- Accessibility smoke against the served SPA.
- Docker Compose config validation.
- OTel Collector and Prometheus config validation.
- OpenAPI contract lint and generated client drift check.
- PHPStan/Larastan static analysis.
- Terraform fmt and validate for LocalStack infrastructure.
- Trivy image scan for runtime images.
- LocalStack Terraform apply and HTTPS smoke.
- Container image build.

## AWS OIDC Smoke

Real AWS smoke tests must use GitHub Actions OIDC to assume an IAM role. They must not use static AWS credentials and must skip clearly when the role ARN is not configured.

## References

- GitHub Actions OIDC for AWS: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services
- Docker build with GitHub Actions: https://docs.docker.com/build/ci/github-actions/
- OpenTelemetry Collector deployment: https://opentelemetry.io/docs/collector/deployment/
