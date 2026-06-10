# CI/CD Runbook

## Current CI

Ordinary CI is designed to run without enterprise IAM credentials.

- `.github/workflows/pest.yml` builds the app image and runs Pest through Docker Compose.
- `.github/workflows/pint.yml` runs Pint through Docker Compose.
- `.github/workflows/frontend.yml` installs Node dependencies, checks generated OpenAPI client drift, typechecks, tests, and builds the SPA through Docker Compose.
- `.github/workflows/accessibility.yml` starts the production-like stack and runs axe/Pa11y against the HTTPS SPA.
- `.github/workflows/containers.yml` builds application images, scans them with Trivy (`vuln,secret,config` at HIGH/CRITICAL), and can publish to GHCR with `GITHUB_TOKEN`. Only the two custom images (`poc-app`, `poc-nginx`) are published; stock images are pulled from upstream.
- `.github/workflows/quality.yml` runs Composer validation, Larastan/PHPStan, OpenAPI lint/client drift, Terraform validate and observability config validation.
- `.github/workflows/localstack-smoke.yml` provisions LocalStack with Terraform and smoke-tests the local stack, including the observability services.
- `.github/workflows/aws-smoke.yml` is manual-only (`workflow_dispatch`, never blocking) and smoke-tests real S3, Textract and Bedrock. See "AWS Smoke" below.

All workflows use a `concurrency` group per workflow/ref with `cancel-in-progress`, so superseded pushes do not waste runner minutes. Workflows that pull the Compose stack pre-pull images with retry/backoff and log in to Docker Hub when the `DOCKERHUB_USERNAME`/`DOCKERHUB_TOKEN` secrets exist, to absorb registry rate limits on shared runners.

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

## AWS Smoke

`aws-smoke.yml` verifies the real AWS integrations (S3 put/head/delete, Textract `detect-document-text`, Bedrock `converse`) and supports two credential modes, in order of preference:

1. **OIDC (target state)**: pass the IAM role ARN as the `aws_role_arn` input; the workflow assumes it via GitHub OIDC (`id-token: write`). No stored credentials.
2. **Ephemeral secrets (interim)**: load short-lived session credentials as `AWS_REAL_ACCESS_KEY_ID` / `AWS_REAL_SECRET_ACCESS_KEY` / `AWS_REAL_SESSION_TOKEN` repository secrets right before an important PR, run the workflow, then let them expire. Long-lived static credentials must not be stored.

Non-sensitive configuration comes from repository variables (`AWS_REAL_REGION`, `AWS_REAL_S3_BUCKET`, `BEDROCK_REGION`, `BEDROCK_MODEL_ID`, ...) with defaults aligned to `docker-compose.yml`. When neither credential source is available the workflow skips cleanly with a notice.

## References

- GitHub Actions OIDC for AWS: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services
- Docker build with GitHub Actions: https://docs.docker.com/build/ci/github-actions/
- OpenTelemetry Collector deployment: https://opentelemetry.io/docs/collector/deployment/
