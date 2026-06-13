# Frontend SPA

React/Vite/TypeScript SPA for the document pipeline PoC.

## Structure

- `src/app`: bootstrap, providers and top-level view composition.
- `src/components`: reusable UI primitives and layout components.
- `src/features`: domain modules for assistant, communications, documents, observability and state.
- `src/api/generated`: Orval generated client. Do not edit manually.
- `src/api/pocApi.ts`: stable adapter around the generated client.
- `src/lib`: formatting, errors and status helpers.
- `src/styles`: global tokens, base styles and minimal utilities.

## Commands

From the repository root:

```bash
make openapi-generate
make frontend-typecheck
make frontend-test
make frontend-build
```

The root package uses npm workspaces. Keep frontend dependencies in `apps/frontend/package.json` and orchestration scripts in the root `package.json`/`Makefile`.
