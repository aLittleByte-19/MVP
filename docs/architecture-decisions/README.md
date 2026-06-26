# Architecture Decision Records (ADR)

In questo repository **ADR** indica esclusivamente *Architecture Decision Record*: la
registrazione breve e datata di una decisione architetturale significativa, del suo contesto
e delle sue conseguenze.

> **Nota terminologica.** «ADR» **non** è l'abbreviazione di «Analisi dei Requisiti». Il
> documento di riferimento per i requisiti di business è il **Capitolato**
> (`[NEXUM] BRD-FASE02-2025`); la corrispondenza tra requisiti e scelte tecniche è in
> [`../architecture/capitolato-traceability.md`](../architecture/capitolato-traceability.md).

## Formato

Ogni ADR segue la struttura [Michael Nygard](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions),
estesa con alcuni campi per la tracciabilità verso la codebase:

- **Status** — `Proposed` · `Accepted` · `Superseded` · `Deprecated` (con eventuale `implemented`/`implemented baseline`)
- **Date** — data della decisione
- **Context** — forze in gioco e vincoli al momento della decisione
- **Decision** — la scelta adottata
- **Consequences** — effetti positivi e negativi che ne derivano
- **Alternatives considered** — le opzioni realistiche valutate e perché scartate
- **Implementation evidence** — dove la decisione si vede nella codebase (path)
- **Related documents** — ADR e documenti correlati che approfondiscono il tema

Gli ADR sono immutabili: una decisione che cambia non si riscrive, si **supera** con un nuovo
ADR che referenzia il precedente. La numerazione è progressiva e a quattro cifre
(`NNNN-titolo-in-kebab-case.md`).

## Indice delle decisioni

| ID | Decisione | Status |
|----|-----------|--------|
| [0001](0001-frontend-spa.md) | Frontend come SPA servita da Nginx, decisione iniziale | Superseded by 0008 |
| [0002](0002-laravel-api-json.md) | Backend Laravel come API JSON versionata (`/api/v1`) | Accepted, implemented |
| [0003](0003-sqs-instead-of-redis-queue.md) | Code asincrone su SQS; Redis solo per cache/sessioni | Accepted, implemented |
| [0004](0004-localstack-terraform.md) | Emulazione AWS locale con LocalStack + Terraform | Accepted, implemented |
| [0005](0005-no-automatic-fallbacks.md) | Nessun fallback automatico dei servizi AI: stato `failed` esplicito | Accepted, implemented |
| [0006](0006-observability-and-audit.md) | Osservabilità (OTel/Prometheus) e audit trail append-only | Accepted, implemented baseline |
| [0007](0007-authn-authz-boundary.md) | Confine authn/authz: IdP simulato, RBAC/ABAC server-side | Accepted, implemented baseline |
| [0008](0008-angular-frontend-static-serving.md) | Frontend Angular e serving statico S3 locale + emulatore CDN locale (Nginx) | Accepted, implemented |

## Aggiungere un ADR

1. Copia il numero successivo libero e crea `NNNN-titolo.md`.
2. Compila Status/Date/Context/Decision/Consequences e, dove utile, Alternatives considered,
   Implementation evidence e Related documents.
3. Aggiungi la riga corrispondente alla tabella qui sopra.
4. Se la decisione ne supera una precedente, imposta lo Status del vecchio ADR a `Superseded`
   e linka il nuovo.
