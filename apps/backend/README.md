# Backend Target Location

This folder is reserved for the Laravel API target layout.

The current Laravel 12 application still runs from the repository root. It must not be moved here until a dedicated migration milestone updates Composer paths, Docker build context, CI, tests, and runtime volume mounts together.

Target responsibilities:

- JSON API under `/api/v1`;
- OpenAPI-backed contract;
- Form Request validation;
- service-layer application behavior;
- RBAC plus ABAC authorization;
- audit events;
- SQS workers;
- S3/Textract/Bedrock integrations without automatic fallbacks.

