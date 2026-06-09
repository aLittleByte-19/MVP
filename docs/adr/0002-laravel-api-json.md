# ADR 0002 - Laravel API JSON

Status: Accepted and implemented

## Context

Laravel exposes the backend contract for the SPA and worker pipeline. Product flows must use a versioned API, stable validation, server-side authorization, tenant scoping, and consistent error envelopes.

## Decision

Expose product flows only under `/api/v1`. Use focused API controllers, Form Requests for validation, service classes for orchestration, and a centralized exception renderer for validation, authentication, authorization, not found, HTTP, and upstream AI errors.

The runtime does not expose configuration or administration UI routes. Operational visibility is provided through health/readiness, audit records, JSON logs, metrics, and containerized observability services.

## Consequences

- API behavior is covered by Pest tests and the OpenAPI contract.
- Removed runtime routes must remain blocked by Nginx and covered by tests.
- Any new write endpoint must include validation, authorization, audit context where relevant, and generated OpenAPI schema updates.
