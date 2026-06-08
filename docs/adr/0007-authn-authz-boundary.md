# ADR 0007 - Authn Authz Boundary

Status: Accepted for migration baseline

## Context

The current PoC routes are public and do not model enterprise identity. The target architecture must simulate that authentication has already happened through a corporate IdP while implementing authorization in Laravel.

## Decision

Do not implement a real IdP in this PoC. Add a local/test-only identity provider middleware that injects structured claims equivalent to enterprise OIDC/SAML claims: user ID, email, display name, tenant/company, groups/roles, and application attributes.

Use Laravel policies/services to enforce RBAC plus ABAC server-side. The frontend may hide unavailable actions for UX, but it must never be the source of truth for authorization.

## Consequences

- Local identity simulation must be clearly isolated from production mode.
- Authorization tests must cover allowed and denied access.
- Relevant denied decisions and sensitive authorization events must be audited.

## References

- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- GitHub Actions AWS OIDC model for future CI credentials: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services

