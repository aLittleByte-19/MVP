# Auth Boundary

Authentication is intentionally simulated in this PoC.

## Implemented

- `poc.identity` middleware resolves a structured `PocUser`.
- Local mode injects a deterministic user/tenant/role from configuration.
- Trusted-header mode requires complete identity headers.
- `poc.authorize` requires configured roles.
- Document APIs check tenant ownership before stream, preview or delete.
- `/admin` and legacy runtime admin paths return 404 through Nginx.

## Not Implemented

- Enterprise OIDC login.
- JWT/JWKS validation.
- SCIM/group synchronization.
- Production session hardening beyond local Redis/session configuration.
- Final RBAC/ABAC policy model.

## Reason

The PoC objective is to validate the document AI pipeline, workflow orchestration, storage boundary and observability. Real auth belongs to the deployment/platform boundary and requires enterprise IdP details that are not available in this repository.

## Production Direction

1. Terminate OIDC at the edge or API gateway.
2. Validate signed tokens server-side or pass verified claims from a trusted gateway.
3. Map enterprise groups to application roles.
4. Replace local mode with deny-by-default production configuration.
5. Emit denied-action audit events for privileged flows.
