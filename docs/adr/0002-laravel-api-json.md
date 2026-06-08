# ADR 0002 - Laravel API JSON

Status: Accepted

## Context

The current Laravel app mixes Blade views, JSON endpoints, SSE streaming, serialization, and service orchestration. The target architecture requires Laravel to act as a JSON API, with versioned endpoints and consistent error responses.

## Decision

Expose main product flows under `/api/v1`. Use API controllers, Form Requests for validation, service classes for application behavior, JSON response envelopes, and an exception renderer that returns consistent errors for validation, unauthorized, forbidden, not found, conflict, and server errors.

Do not add new Blade views for main flows. Keep current Blade routes only until the SPA replacement is verified.

## Consequences

- Frontend/backend integration is contract-driven through OpenAPI.
- Tests must cover endpoint behavior and error envelopes.
- Existing `/poc/api/*` endpoints need a compatibility or migration plan before removal.

## References

- Laravel validation: https://laravel.com/docs/12.x/validation
- Laravel testing: https://laravel.com/docs/12.x/testing
- Laravel deployment: https://laravel.com/docs/12.x/deployment
