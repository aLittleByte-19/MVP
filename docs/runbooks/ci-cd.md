# CI/CD Runbook

## Current CI

Current workflows:

- `.github/workflows/pest.yml` runs Composer install and Laravel tests.
- `.github/workflows/pint.yml` runs Pint.
- `.github/workflows/accessibility.yml` starts Docker Compose and runs axe/Pa11y against `/` and `/admin`.

## Target CI

Target ordinary CI should include:

- backend dependency install;
- Pint;
- Pest;
- PHPStan/Larastan;
- frontend dependency install;
- frontend lint;
- frontend typecheck;
- frontend unit/component tests;
- Playwright representative tests;
- OpenAPI validation;
- generated client drift check;
- Terraform fmt/validate/plan for `infra/local`;
- Docker image builds;
- vulnerability scan;
- production-like Compose startup;
- LocalStack Terraform apply;
- smoke tests for API, SQS, Step Functions, EventBridge, SES, SSM, Secrets Manager, and the document pipeline.

## GHCR

Images should be built and pushed to GitHub Container Registry with GitHub Actions using the repository `GITHUB_TOKEN`, not static registry credentials.

## AWS OIDC Smoke

Real AWS smoke tests for S3, Textract, and Bedrock must be a separate conditional/manual job. It must use GitHub Actions OIDC to assume an AWS IAM role. It must skip clearly if the role ARN is not configured and must not block ordinary CI until enterprise AWS permissions exist.

## References

- GHCR publishing: https://docs.github.com/en/actions/use-cases-and-examples/publishing-packages/publishing-docker-images
- GitHub Actions OIDC for AWS: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services
- Docker build with GitHub Actions: https://docs.docker.com/build/ci/github-actions/

