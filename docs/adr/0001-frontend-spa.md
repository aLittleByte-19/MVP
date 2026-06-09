# ADR 0001 - Frontend SPA

Status: Accepted and implemented

## Context

The runtime serves a React + TypeScript + Vite SPA from the Nginx image. Laravel is the backend API provider and does not render product UI views.

## Decision

Keep the frontend under `apps/frontend`. Generate the TypeScript client from `openapi/v1/alittlebyte-poc-api.yaml` through Orval, use TanStack Query for server state, React Hook Form for form handling, Vitest for component tests, and containerized axe/Pa11y checks for representative accessibility validation.

Authorization decisions remain server-side. The SPA may hide actions for ergonomics, but it must never be the source of truth for access control.

## Consequences

- The Nginx runtime image owns static SPA delivery.
- Laravel owns `/api/v1/*`, `/health`, `/ready`, and internal metrics.
- Generated API clients are contract artifacts and must not be hand-edited.
- Frontend tooling runs through Docker Compose, not host Node.
