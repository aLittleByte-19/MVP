# Struttura del repository

Il repository mantiene le convenzioni Laravel, separando i confini di runtime dalla logica di
dominio.

- `app/Mvp`: codice applicativo di dominio del co-pilot AI.
- `app/Mvp/Ai`: integrazione Bedrock e servizi specifici dell'AI.
- `app/Mvp/Audit`: servizi di audit logging.
- `app/Mvp/Documents`: dominio documentale (Co-Pilot), incluso OCR Textract. Architettura esagonale
  (ports & adapters, [ADR 0010](../architecture-decisions/0010-hexagonal-architecture-documents-communications.md)):
  `Domain/` (porte, value object, eccezioni), `Application/UseCases/` (casi d'uso), `Adapters/`
  (`Primary/` guidati da HTTP/Step Functions, `Outbound/` verso Eloquent/Textract/Bedrock/storage).
- `app/Mvp/Communications`: dominio generativo (AI Assistant), copertine e impaginazione del PDF
  finale. Stessa architettura esagonale di `Documents` (ADR 0010).
- `app/Mvp/Identity`: identità utente risolta a runtime.
- `app/Mvp/Observability`: exporter Prometheus e registrazione delle metriche.
- `app/Mvp/Support`: servizi trasversali a piu' domini (stato applicativo esposto alla SPA, caricamento della configurazione runtime, piè di pagina condiviso dei PDF generati).
- `app/Mvp/Workflow`: infrastruttura di orchestrazione Step Functions/SQS comune alle pipeline (contratto degli handler, registry, runner, heartbeat, contesto di correlazione). I servizi specifici di un flusso vivono nella cartella del flusso.
- `app/Console/Commands`: comandi artisan, incluso il worker `mvp:workflow:consume`.
- `app/Http`: controller HTTP, middleware e validazione delle richieste.
- `app/Models`: model Eloquent del dominio MVP.
- `apps/frontend`: SPA Angular/TypeScript.
- `openapi/v1`: contratto API versionato.
- `infra/localstack`: modello Terraform LocalStack per le esecuzioni locali production-like.
- `infra/modules`: moduli Terraform riutilizzabili per la futura infrastruttura AWS.
- `infra/aws`: placeholder per la futura baseline di prodotto su AWS reale.
- `docker`: immagini di runtime locali e configurazione dei servizi.
