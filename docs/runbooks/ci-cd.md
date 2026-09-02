# CI/CD Runbook

## Current CI

Ordinary CI runs without enterprise IAM credentials. It is a single pipeline,
`.github/workflows/ci.yml`.

It runs on every push to every branch, on every pull request, on `v*` tags and
on manual dispatch. Running on feature branches too is deliberate: whoever is
working on a branch sees the suites go red while they are still developing,
instead of discovering it when the branch is already up for merge.

The pipeline has four jobs through Docker Compose:

- **backend**: builds the app image and runs the PHP checks: Composer manifest validation, Pint (format), Larastan/PHPStan (static analysis), Pest and Xdebug line/branch coverage, and finally `composer audit` on the production dependencies. Xdebug is installed only when `COMPOSER_INSTALL_DEV=true`, so it is absent from the production image. The audit runs last, like its npm counterpart in the frontend job, so an advisory never hides the test outcome.
- **frontend**: runs the Angular SPA suite on the Node tool image: OpenAPI contract lint, generated client drift check, ESLint, typecheck, Jest tests with statement/function/branch coverage, production build, and a production-only `npm audit` at HIGH. The typecheck covers the test suites as well as the app (`tsconfig.spec-typecheck.json`): Jest transpiles specs without checking types, so without this step a test could reference fields that do not exist on the generated API model and still pass.
- **coverage-diff**: downloads the Cobertura and LCOV artifacts from the two test jobs and applies the changed-line gate against `origin/develop` with `diff-cover==10.4.1`. The two stacks are measured in separate invocations, because `diff-cover` refuses XML and LCov reports in the same run; each report only describes its own files, so the two measurements never overlap and no line is counted twice.
- **stack** (static infrastructure checks (Terraform `fmt`/`init`/`validate`), production image build, Trivy scan (`vuln,secret,config` at HIGH/CRITICAL), LocalStack Terraform apply, Angular SPA build and upload to the LocalStack S3 bucket, HTTPS smoke of the served stack (SPA served via the local CDN emulator, a separate Nginx: with deep-link fallback, `/api`/`/health`/`/ready`, blocked surfaces), accessibility (axe/Pa11y plus an enforced-CSP smoke), and conditional publish of the two custom images (`mvp-app`, `mvp-nginx`) to GHCR.

Supporting workflows:

- `.github/workflows/aws-smoke.yml` is manual-only (`workflow_dispatch`, never blocking) and smoke-tests real S3, Textract and Bedrock. See "AWS Smoke" below.
- `.github/workflows/mirror-images.yml` mirrors the external base images used by CI onto GHCR (scheduled weekly and on image-list change), so the jobs pull from an authenticated mirror instead of anonymous, rate-limited registries.

All jobs use a `concurrency` group per workflow/ref with `cancel-in-progress`, so superseded pushes do not waste runner minutes. Jobs that pull the Compose stack pre-pull images with retry/backoff and log in to GHCR (and to Docker Hub when the `DOCKERHUB_USERNAME`/`DOCKERHUB_TOKEN` secrets exist) to absorb registry rate limits on shared runners.

## Required Gates Before Deployment

- Backend: Composer validation, Pint formatting, Larastan/PHPStan static analysis, Pest tests, line coverage and branch coverage, production `composer audit`.
- Frontend: OpenAPI contract lint, generated client drift check, ESLint, typecheck, Jest tests, statement/function/branch coverage, production build, production `npm audit`.
- Changed code: backend and frontend diff coverage at 80%, measured one stack at a time.
- Terraform fmt and validate for LocalStack infrastructure.
- Trivy image scan for runtime images (HIGH/CRITICAL).
- LocalStack Terraform apply, Angular SPA build and upload to S3, and HTTPS smoke (SPA serving with deep-link fallback, API, blocked surfaces).
- Accessibility smoke (axe/Pa11y) and enforced-CSP smoke against the served SPA.
- Container image build (and conditional GHCR publish).

## Dependency advisory gates

Node and PHP dependencies are both audited, but the two ecosystems need different thresholds.

`npm audit --omit=dev --audit-level=high` is enough on the Node side: the npm advisory database
always carries a severity, so filtering on it is reliable.

PHP needs `scripts/ci/check-composer-advisories.mjs`, because `composer audit` cannot filter by
severity at all — it either passes or fails on everything. The script reads the JSON report and
fails on `high`, `critical` **and on advisories with no severity declared**, letting only explicit
`low` and `moderate` through. That last case is not a corner case: the PHP advisory source
(`FriendsOfPHP/security-advisories`) often leaves `severity` at `null`, as it did for
CVE-2026-54133 on `mtdowling/jmespath.php`, which GitHub rates 9.8 critical. A gate filtering
strictly on `high` would have let that one through.

Two flags carry weight:

- `--locked` audits what the lockfile declares instead of the `vendor/` baked into the image, so
  the gate does not silently describe whatever was installed when the image was last built.
- `--abandoned=report` is required, not cosmetic: the project config sets `audit.abandoned` to
  `fail`, so without it the job would break the day a package is marked abandoned — which is not a
  vulnerability.

When an advisory cannot be fixed because it depends on an upstream release, declare it in
`composer.json` under `config.audit.ignore` with a reason, so the exception is explicit and
reviewable, instead of weakening the gate for everything.

## Coverage gates

The source of truth is `coverage-thresholds.json`:

- backend: 80% lines and 70% branches;
- frontend: 80% statements, 80% functions and 70% branches;
- changed lines: 80%, read from the same file by the `coverage-diff` job.

The minimum values are strict: total coverage below any minimum always fails the job, without tolerance or manually maintained coverage baselines. Jest enforces the exact frontend minimums through `coverageThreshold.global`. On the backend `scripts/ci/check-coverage-thresholds.mjs` is the only global gate and validates every aggregate metric: Pest no longer passes `--min`, which repeated the same line threshold on the same metric, hardcoded in the workflow and blind to branches. The separate changed-line gate keeps new and modified code at 80% coverage, following a Clean as You Code policy.

Local commands:

```bash
make backend-coverage
make frontend-coverage
```

Backend path coverage is intentionally slower than the ordinary Pest suite and uses a 1 GB PHP memory limit. Reports are generated under `coverage/` and `apps/frontend/coverage/`; both paths are ignored by Git. The CI uploads Cobertura and backend HTML reports, plus LCOV, JSON and frontend HTML reports.

PHPUnit writes the Cobertura report with the container paths: `<source>` holds the absolute path of the covered root and every `filename` is relative to it. `scripts/ci/normalize-cobertura-paths.mjs` rewrites `<source>` to a repo-relative path before the report leaves the backend job, because `diff-cover` resolves each class as `join(<source>, filename)`. Without that step no changed file is matched, the gate measures zero lines and passes silently; the script fails the job instead if the report does not have the expected shape. The `coverage-diff` job then runs one invocation per stack:

```bash
diff-cover coverage/backend/cobertura.xml \
  --compare-branch=origin/develop --fail-under=80
diff-cover coverage/frontend/lcov.info \
  --compare-branch=origin/develop --fail-under=80
```

Both invocations always run and their exit codes are combined, so a failure on one stack still produces the other's report. A commit that touches a single stack leaves the other invocation with no measured line, which `diff-cover` treats as a pass. The job publishes HTML and Markdown reports per stack. Coverage data artifacts are retained for 1 day, HTML and Markdown reports for 7. If a stack smoke or accessibility step fails, the `stack-diagnostics` artifact contains `docker compose ps`, timestamped Compose logs and Docker disk usage. The stack is still stopped by the following `if: always()` cleanup step.

## AWS Smoke

`aws-smoke.yml` verifies the real AWS integrations (S3 put/head/delete, Textract `detect-document-text`, Bedrock `converse`) and supports two credential modes, in order of preference:

1. **OIDC (target state)**: pass the IAM role ARN as the `aws_role_arn` input; the workflow assumes it via GitHub OIDC (`id-token: write`). No stored credentials.
2. **Ephemeral secrets (interim)**: load short-lived session credentials as `AWS_REAL_ACCESS_KEY_ID` / `AWS_REAL_SECRET_ACCESS_KEY` / `AWS_REAL_SESSION_TOKEN` repository secrets right before an important PR, run the workflow, then let them expire. Long-lived static credentials must not be stored.

Non-sensitive configuration comes from repository variables (`AWS_REAL_REGION`, `AWS_REAL_S3_BUCKET`, `BEDROCK_REGION`, `BEDROCK_MODEL_ID`, ...) with defaults aligned to `docker-compose.yml`. When neither credential source is available the workflow skips cleanly with a notice.

## References

- GitHub Actions OIDC for AWS: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services
- Docker build with GitHub Actions: https://docs.docker.com/build/ci/github-actions/
- OpenTelemetry Collector deployment: https://opentelemetry.io/docs/collector/deployment/
