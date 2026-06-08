# OWASP ASVS Mapping

This mapping is a PoC baseline, not a claim of full ASVS compliance.

| Area | Implemented now | Target PB | Evidence/tests |
| --- | --- | --- | --- |
| Input validation | Form Requests validate upload, communication generation, and settings inputs. | Versioned API Form Requests for every write endpoint; strict file size/content type checks; OpenAPI schema validation in CI. | `app/Poc/Requests/*`, `tests/Feature/*` |
| Authentication | Current PoC is public. | Local/test-only structured identity claims; production mode expects enterprise IdP-authenticated identity at the boundary. | ADR 0007 |
| Authorization | No RBAC/ABAC enforcement exists yet. | Laravel policies/services enforce RBAC plus ABAC for tenant, document ownership, action, and attributes. | Future policy tests |
| Error handling | Some JSON errors exist for AI/token exceptions. | Consistent JSON error envelope for validation, unauthorized, forbidden, not found, conflict, and server error without sensitive leakage. | Future `/api/v1` tests |
| Secrets | Runtime secrets are loaded from Secrets Manager; non-sensitive values are loaded from SSM Parameter Store. | Scoped cloud identity, no static AWS credentials in CI, no secrets in logs. | Runbook `aws-permissions-needed.md` |
| Logging | Some ad hoc logs exist. | JSON logs with request/correlation IDs and redaction rules. | ADR 0006 |
| Audit | No append-only audit table yet. | Append-only `audit_events` table for upload, pipeline, dispatch, access denied, and document state changes. | Future migration and tests |
| Upload security | Upload request exists for PDFs. | Validate content type, extension, size, object key ownership, and S3 finalization metadata server-side. | Future S3 upload tests |
| Access control for documents | Preview/delete routes are public today. | Backend authorization on every document read/write/delete/preview action. | Future policy tests |
| Rate limiting | Current `/poc/api/*` routes use Laravel throttling. | Apply scoped API rate limits using Redis and identity/tenant-aware keys. | `routes/web.php`, future API middleware tests |
| Security headers/CORS | Not explicitly baselined yet. | Configure CORS for SPA/API boundary and security headers at Traefik/Nginx/Laravel where appropriate. | Future browser and header tests |

## Primary Reference

- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
