# ADR 0001 — Frontend come SPA

Status: Superseded by [0008 — Frontend Angular e serving statico LocalStack](0008-angular-frontend-static-serving.md)
Date: 2026-06-08

> Historical ADR. This records the original frontend decision; the active frontend decision is ADR 0008.

## Context

La decisione iniziale prevedeva una SPA React + TypeScript + Vite servita dall'immagine Nginx.
Laravel era gia' il provider dell'API di backend e non renderizzava viste di prodotto. Serviva un
frontend disaccoppiato dal backend ma allineato al contratto API, con autorizzazione che restasse
lato server.

## Decision

Mantenere il frontend iniziale in `apps/frontend`. Generare il client TypeScript da
`openapi/v1/alittlebyte-mvp-api.yaml` tramite Orval, usare TanStack Query per lo stato server,
React Hook Form per la gestione dei form, Vitest per i component test e controlli axe/Pa11y in
container per una validazione di accessibilita' rappresentativa.

Le decisioni di autorizzazione restano lato server. La SPA può nascondere azioni per ergonomia,
ma non deve mai essere la fonte di verità per il controllo degli accessi.

## Consequences

- L'immagine di runtime Nginx possiede il serving statico della SPA.
- Laravel possiede `/api/v1/*`, `/health`, `/ready` e le metriche interne.
- I client API generati sono artefatti di contratto e non vanno modificati a mano.
- Il tooling frontend gira tramite Docker Compose, non con Node sull'host.

## Alternatives considered

- **Frontend renderizzato da Laravel (Blade/Inertia)**: scartato perché accoppia UI e backend e
  non valorizza un confine API versionato riutilizzabile da altri client.
- **Client API scritto a mano**: scartato per il rischio di deriva rispetto al contratto OpenAPI;
  la generazione automatica elimina la divergenza dei tipi.

## Implementation evidence

- `apps/frontend/` (SPA), `apps/frontend/orval.config.ts` e `src/api/generated/` (client generato).
- `docker/nginx/Dockerfile` (build multi-stage della SPA + runtime `nginx:1.27-alpine`).
- CI: step "Check generated client is committed" in `.github/workflows/ci.yml`.
- Audit a11y: `scripts/a11y/axe-playwright.mjs`, `scripts/a11y/pa11y-runner.mjs`.

## Related documents

- [`0002-laravel-api-json.md`](0002-laravel-api-json.md)
- [`0007-authn-authz-boundary.md`](0007-authn-authz-boundary.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§5, §11)
