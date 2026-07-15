# Frontend SPA

Angular/TypeScript SPA for the document pipeline MVP.

## Structure

- `src/app`: Angular bootstrap, routes, shell, core services, shared components and feature pages.
- `src/app/core`: navigation model, API interceptors, correlation IDs, structured logging, global errors, theme and state store.
- `src/app/features`: assistant, overview and document Co-Pilot pages.
- `src/api/generated`: Orval generated Angular/HttpClient service and model types. Do not edit manually.
- `src/styles`: global tokens, base styles and minimal utilities.
- `public`: static assets copied into the Angular production build.

## Commands

From the repository root:

```bash
make openapi-generate
make frontend-lint
make frontend-typecheck
make frontend-test
make frontend-build
make frontend-s3-local-deploy
```

The root package uses npm workspaces. Frontend commands run through the Docker Compose `node` tool container (`node:22-bookworm-slim`), not through the host Node runtime.

The app calls Laravel with relative `/api/v1` URLs by default. `proxy.conf.json` keeps `ng serve` aligned with the local Traefik/Nginx entrypoint, while production builds are static and can be served by Nginx or, in the default local flow, by the local CDN emulator (a separate Nginx) in front of the LocalStack S3 bucket — the role a real CDN such as AWS CloudFront would play in production.
