# ADR 0008 — Frontend Angular e serving statico LocalStack

Status: Accepted, implemented
Date: 2026-06-24

## Context

La PoC espone una SPA per operatori HR/CdL e mantiene Laravel come API JSON versionata. Il
Capitolato cita una dashboard Angular e un pattern di distribuzione statico su S3 + CloudFront.
La repository usa gia' Docker Compose, Terraform e LocalStack per modellare servizi AWS-like
locali.

## Decision

Il frontend attivo e' una SPA Angular/TypeScript in `apps/frontend`, buildata con Angular CLI e
servita in produzione locale da Nginx. Orval genera un servizio Angular basato su HttpClient dal
contratto OpenAPI, cosi' i componenti non istanziano HttpClient direttamente per le API di
dominio.

La navigazione usa Angular Router per le tre viste top-level (`overview`, `assistant`,
`copilot`) mantenendo lo stesso layout operativo e gli stessi anchor interni. Lo stato condiviso
vive in uno store Angular a signal; SSE, upload multipart, preview PDF, dark mode, error states e
loading states restano espliciti.

Terraform LocalStack provisiona anche un bucket S3 dedicato agli asset Angular. Il percorso
default locale passa da Traefik al servizio Docker `frontend-cloudfront`, che emula il ruolo di
CloudFront davanti al bucket S3 LocalStack e inoltra `/api/`, `/health` e `/ready` a
Nginx/Laravel. Il deploy locale carica `apps/frontend/dist` nel bucket con cache-control
differenziato: `index.html` no-cache, bundle hashati immutable, altri asset con cache breve.

![Frontend SPA e contratto API](../architecture/diagrams/03_frontend_spa_contratto_api.drawio.png)

<sub>Sorgente editabile: [`03_frontend_spa_contratto_api.drawio`](../architecture/diagrams/03_frontend_spa_contratto_api.drawio), export [`SVG`](../architecture/diagrams/03_frontend_spa_contratto_api.drawio.svg).</sub>

## Consequences

- Il frontend applicativo resta su Angular, Angular Router, HttpClient, RxJS e signal store.
- La build statica Angular non dipende dal dev server.
- Traefik e `frontend-cloudfront` sono il percorso integrato default per demo end-to-end.
- S3 LocalStack + CloudFront locale valida il pattern build → bucket → CDN-like distribution,
  non la semantica completa di CloudFront reale.
- I documenti possono usare `POC_DOCUMENT_DISK=real_s3` e `AWS_REAL_*` per Textract reale senza
  toccare `FRONTEND_STATIC_BUCKET`, che resta dedicato alla SPA locale.

## Alternatives considered

- **Mantenere solo Nginx**: semplice, ma non valida il pattern static-hosting richiesto.
- **Usare l'API CloudFront LocalStack Terraform**: scartato perché l'immagine LocalStack locale
  risponde `501` per CloudFront; il default resta comunque S3 locale + CloudFront locale tramite
  servizio Docker.
- **Client API scritto a mano**: scartato per evitare deriva dal contratto OpenAPI.

## Implementation evidence

- `apps/frontend/angular.json`, `src/app/`, `src/api/generated/`.
- `apps/frontend/orval.config.ts` con `client: "angular"`.
- `docker/nginx/Dockerfile` builda Angular con `node:22-bookworm-slim`.
- `infra/localstack/main.tf` risorsa `aws_s3_bucket.frontend_static`.
- `docker/cloudfront/default.conf.template` e servizio Compose `frontend-cloudfront`.
- `Makefile` target `frontend-s3-local-*`, `frontend-cloudfront-local-url` e
  `frontend-serving-local-test`.

## Related documents

- [`0001-frontend-spa.md`](0001-frontend-spa.md)
- [`0002-laravel-api-json.md`](0002-laravel-api-json.md)
- [`0004-localstack-terraform.md`](0004-localstack-terraform.md)
