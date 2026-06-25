# CI/CD Runbook

## Current CI

Ordinary CI runs without enterprise IAM credentials. It is a single pipeline,
`.github/workflows/ci.yml`, with three parallel jobs through Docker Compose:

- **backend** — builds the app image and runs the PHP checks: Composer manifest validation, Pint (format), Larastan/PHPStan (static analysis) and Pest (tests).
- **frontend** — runs the Angular SPA suite on the Node tool image: OpenAPI contract lint, generated client drift check, ESLint, typecheck, Jest tests, production build, and a production-only `npm audit` at HIGH.
- **stack** — static infrastructure/observability checks (Terraform `fmt`/`init`/`validate`, OTel Collector and Prometheus config), production image build, Trivy scan (`vuln,secret,config` at HIGH/CRITICAL), LocalStack Terraform apply, Angular SPA build and upload to the LocalStack S3 bucket, HTTPS smoke of the served stack (SPA served via the CloudFront emulator with deep-link fallback, `/api`/`/health`/`/ready`, blocked surfaces, observability dashboards behind basic auth), accessibility (axe/Pa11y plus an enforced-CSP smoke), and conditional publish of the two custom images (`poc-app`, `poc-nginx`) to GHCR.

Supporting workflows:

- `.github/workflows/aws-smoke.yml` is manual-only (`workflow_dispatch`, never blocking) and smoke-tests real S3, Textract and Bedrock. See "AWS Smoke" below.
- `.github/workflows/mirror-images.yml` mirrors the external base images used by CI onto GHCR (scheduled weekly and on image-list change), so the jobs pull from an authenticated mirror instead of anonymous, rate-limited registries.

All jobs use a `concurrency` group per workflow/ref with `cancel-in-progress`, so superseded pushes do not waste runner minutes. Jobs that pull the Compose stack pre-pull images with retry/backoff and log in to GHCR (and to Docker Hub when the `DOCKERHUB_USERNAME`/`DOCKERHUB_TOKEN` secrets exist) to absorb registry rate limits on shared runners.

## Required Gates Before Deployment

- Backend: Composer validation, Pint formatting, Larastan/PHPStan static analysis, Pest tests.
- Frontend: OpenAPI contract lint, generated client drift check, ESLint, typecheck, Jest tests, production build, production `npm audit`.
- OTel Collector and Prometheus config validation.
- Terraform fmt and validate for LocalStack infrastructure.
- Trivy image scan for runtime images (HIGH/CRITICAL).
- LocalStack Terraform apply, Angular SPA build and upload to S3, and HTTPS smoke (SPA serving with deep-link fallback, API, blocked surfaces).
- Accessibility smoke (axe/Pa11y) and enforced-CSP smoke against the served SPA.
- Container image build (and conditional GHCR publish).

## AWS Smoke

`aws-smoke.yml` verifies the real AWS integrations (S3 put/head/delete, Textract `detect-document-text`, Bedrock `converse`) and supports two credential modes, in order of preference:

1. **OIDC (target state)**: pass the IAM role ARN as the `aws_role_arn` input; the workflow assumes it via GitHub OIDC (`id-token: write`). No stored credentials.
2. **Ephemeral secrets (interim)**: load short-lived session credentials as `AWS_REAL_ACCESS_KEY_ID` / `AWS_REAL_SECRET_ACCESS_KEY` / `AWS_REAL_SESSION_TOKEN` repository secrets right before an important PR, run the workflow, then let them expire. Long-lived static credentials must not be stored.

Non-sensitive configuration comes from repository variables (`AWS_REAL_REGION`, `AWS_REAL_S3_BUCKET`, `BEDROCK_REGION`, `BEDROCK_MODEL_ID`, ...) with defaults aligned to `docker-compose.yml`. When neither credential source is available the workflow skips cleanly with a notice.

## References

- GitHub Actions OIDC for AWS: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services
- Docker build with GitHub Actions: https://docs.docker.com/build/ci/github-actions/
- OpenTelemetry Collector deployment: https://opentelemetry.io/docs/collector/deployment/
