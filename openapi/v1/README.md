# OpenAPI v1

This directory contains the versioned JSON API contract consumed by the Angular SPA.

The canonical file is `alittlebyte-mvp-api.yaml`.

Regenerate the TypeScript client with:

```bash
make openapi-generate
```

Do not edit generated files under `apps/frontend/src/api/generated` manually.
