# Valutazione RLS PostgreSQL

## Tabelle Tenant-Scoped

Le tabelle direttamente tenant-scoped sono:

- `original_documents.tenant_id`;
- `communications.tenant_id`;
- `audit_events.tenant_id`.

Le tabelle collegate ereditano il tenant tramite relazioni:

- `sub_documents` via `original_documents`;
- `extracted_data` via `sub_documents -> original_documents`;
- `document_workflow_tasks` via `original_documents`.

## Possibile Modello

PostgreSQL RLS potrebbe usare `current_setting('app.tenant_id', true)`:

```sql
tenant_id = current_setting('app.tenant_id', true)
```

Per le tabelle figlie servirebbero policy con `EXISTS` sulle relazioni padre.

## Dove Laravel Dovrebbe Impostare il Tenant

Il tenant viene risolto in `ResolvePocIdentity`. Per RLS, Laravel dovrebbe impostare il valore sulla connessione database prima di ogni query tenant-scoped, ad esempio con:

```sql
SET LOCAL app.tenant_id = '<tenant>';
```

Il punto naturale sarebbe un middleware dopo `poc.identity`, oppure un wrapper transaction-scoped per ogni request/API job.

## Rischi

- `SET LOCAL` funziona solo dentro transazioni; senza transazione, il valore non copre tutta la request.
- `SET` non locale rischia leakage tra request con connessioni persistenti o pooling.
- Worker asincroni e comandi Artisan non passano sempre dallo stesso middleware HTTP.
- I test cross-tenant andrebbero estesi su tutte le query Eloquent e sui job workflow.
- Le policy sulle tabelle figlie possono introdurre regressioni prestazionali se non indicizzate correttamente.

## Decisione

Non implementare RLS in questo hardening locale. La PoC ha gia' enforcement applicativo su tenant/ruolo nei controller principali; aggiungere RLS ora senza transazioni request-wide e copertura estesa rischierebbe di rompere workflow e test in modo non reversibile.

Effort stimato per una implementazione sicura: 2-4 giorni, includendo middleware DB session state, policy SQL reversibili, test cross-tenant su API e worker, e verifica con PostgreSQL reale nello stack locale.
