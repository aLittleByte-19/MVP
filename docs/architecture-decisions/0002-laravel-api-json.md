# ADR 0002 — Backend Laravel come API JSON

Status: Accepted, implemented
Date: 2026-06-08

## Context

Laravel espone il contratto di backend per la SPA e per la pipeline del worker. I flussi di
prodotto devono usare un'API versionata, una validazione stabile, autorizzazione lato server,
tenant scoping ed envelope di errore consistenti.

## Decision

Esporre i flussi di prodotto solo sotto `/api/v1`. Usare controller API mirati, Form Request per
la validazione, classi di servizio per l'orchestrazione e un renderer di eccezioni centralizzato
per errori di validazione, autenticazione, autorizzazione, not found, HTTP e degli upstream AI.

Il runtime non espone route di configurazione o di UI di amministrazione. La visibilità
operativa è fornita da health/readiness, record di audit, log JSON, metriche e servizi di
osservabilità containerizzati.

## Consequences

- Il comportamento dell'API è coperto dai test Pest e dal contratto OpenAPI.
- Le route di runtime rimosse devono restare bloccate da Nginx e coperte da test.
- Ogni nuovo endpoint di scrittura deve includere validazione, autorizzazione, contesto di audit
  dove rilevante e aggiornamento dello schema OpenAPI generato.

## Alternatives considered

- **UI server-side e route di amministrazione in Laravel**: scartata per ridurre la superficie
  d'attacco e mantenere il backend come puro provider di API.
- **API non versionata**: scartata perché il versioning nel path (`/api/v1`) permette evoluzioni
  del contratto senza rompere i client esistenti.

## Implementation evidence

- `routes/api.php` (flussi sotto `/api/v1`), `app/Http/Controllers/`, `app/Http/Requests/`.
- Mapping centralizzato delle eccezioni in `bootstrap/app.php` (envelope con `code`, `message`,
  `requestId`, `correlationId`).
- Blocco delle superfici legacy in `docker/nginx/default.conf`, verificato dallo smoke CI.
- Test: `tests/Feature/HealthAndApiContractTest.php` e altri Pest in `tests/`.

## Related documents

- [`0001-frontend-spa.md`](0001-frontend-spa.md)
- [`0007-authn-authz-boundary.md`](0007-authn-authz-boundary.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§12)
