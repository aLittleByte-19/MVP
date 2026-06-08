# ADR 0001 - Frontend SPA

Status: Accepted

## Context

The current PoC UI is Laravel Blade plus static JavaScript and CSS under `resources/views/poc` and `public/poc`. The target architecture requires a frontend separated from Laravel, typed API access, client-side routing, and frontend tests.

## Decision

Create the target frontend under `apps/frontend` as a React + TypeScript + Vite SPA. Use React Router for routing, TanStack Query for server state, React Hook Form and Zod for forms, Vitest and React Testing Library for unit/component tests, and Playwright for representative flows.

The SPA must consume Laravel through a generated TypeScript client from the OpenAPI contract. It must not own authorization decisions; all permission enforcement remains server-side.

## Consequences

- Laravel becomes an API provider for main flows.
- Existing Blade pages remain operational until the SPA reaches parity.
- Frontend CI must include lint, typecheck, tests, and representative browser checks.
- The generated client becomes a contract boundary and should not be hand-edited.

## References

- Vite: https://vite.dev/guide/
- React: https://react.dev/
- OpenAPI: https://spec.openapis.org/oas/latest.html
- Frontend quality checks in this repo: `scripts/a11y/axe-playwright.mjs`, `pa11y.json`
