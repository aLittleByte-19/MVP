# OWASP ASVS Mapping

This mapping is a PoC baseline, not a claim of full ASVS compliance.

| Area | Implemented baseline | Remaining production work |
| --- | --- | --- |
| Input validation | Form Requests validate communication generation and PDF upload; JSON validation errors use a stable envelope. | Add contract validation gate for every OpenAPI request/response shape. |
| Authentication | Local mode injects structured user claims; trusted-header mode requires complete identity claims. | Replace local mode with enterprise IdP boundary in the deployment tier. |
| Authorization | API routes enforce configured roles and document tenant ownership server-side. | Add policy classes for each action and deny-event audit for every rejected privileged action. |
| Error handling | API exceptions return consistent JSON envelopes with request and correlation IDs. | Add centralized redaction tests for every upstream provider error class. |
| Secrets/config | Runtime values load from SSM Parameter Store and Secrets Manager; no runtime admin UI edits credentials. | Attach scoped IAM role and remove LocalStack credentials from production profile. |
| Logging/monitoring | JSON logs, request/correlation IDs, audit events, internal metrics, OTel Collector and Prometheus alerts. | Export production telemetry to the enterprise backend and define alert routing/escalation. |
| Audit | `audit_events` records communication generation, document upload, processing lifecycle, and deletion events. | Add immutable retention controls and SIEM forwarding. |
| Upload security | PDF uploads are validated and stored on configured object storage. | Move to presigned S3 finalization with server-side metadata validation and malware scanning hook. |
| Rate limiting | `/api/v1/*` routes use Laravel throttling, with stricter write limits. | Move to identity/tenant-aware Redis keys and document abuse thresholds. |
| Security headers/CORS | Nginx blocks removed runtime surfaces and hidden files. | Finalize production CORS and security header policy at Traefik/Nginx. |

## Primary References

- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- OWASP Top 10 A09 Logging and Monitoring: https://owasp.org/Top10/2021/A09_2021-Security_Logging_and_Monitoring_Failures/
- OWASP API Security Top 10 2023: https://owasp.org/API-Security/editions/2023/en/0x00-header/
