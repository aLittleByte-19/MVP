# ADR 0005 - No Automatic Fallbacks

Status: Accepted

## Context

Document generation, document split and field extraction depend on configured AI services. The runtime must not hide service, model or permission failures by producing substitute data.

## Decision

Do not use automatic fallbacks in main application flows. If a core service required by the pipeline fails or is unavailable, the pipeline must move to an explicit failed state and log structured non-sensitive context.

Mocks and fakes are allowed only in isolated unit tests. End-to-end PoC flows must not silently substitute real AWS services with fake implementations.

## Consequences

- Local development requires LocalStack and clear configuration.
- Tests need separate unit fakes and integration/smoke paths.
- User-facing errors should be clear without leaking credentials, prompts, document content, or other sensitive data.

## References

- AWS Well-Architected reliability pillar: https://docs.aws.amazon.com/wellarchitected/latest/reliability-pillar/welcome.html
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
