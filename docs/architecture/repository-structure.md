# Struttura del repository

Il repository mantiene le convenzioni Laravel, separando i confini di runtime dalla logica di
dominio.

- `app/Copilot`: codice applicativo di dominio del co-pilot AI.
- `app/Copilot/Ai`: integrazione Bedrock e servizi specifici dell'AI.
- `app/Copilot/Audit`: servizi di audit logging.
- `app/Copilot/Documents`: enum, elaborazione e orchestrazione del flusso documentale (Co-Pilot).
- `app/Copilot/Communications`: enum, copertine, impaginazione del PDF finale e orchestrazione del flusso generativo (AI Assistant).
- `app/Copilot/Identity`: identità utente risolta a runtime.
- `app/Copilot/Observability`: exporter Prometheus e registrazione delle metriche.
- `app/Copilot/Ocr`: integrazione OCR Textract.
- `app/Copilot/Support`: servizi trasversali a piu' domini (stato applicativo esposto alla SPA, caricamento della configurazione runtime, piè di pagina condiviso dei PDF generati).
- `app/Copilot/Workflow`: infrastruttura di orchestrazione Step Functions/SQS comune alle pipeline (contratto degli handler, registry, runner, heartbeat, contesto di correlazione). I servizi specifici di un flusso vivono nella cartella del flusso.
- `app/Console/Commands`: comandi artisan, incluso il worker `mvp:workflow:consume`.
- `app/Http`: controller HTTP, middleware e validazione delle richieste.
- `app/Models/Copilot`: model Eloquent del dominio MVP.
- `apps/frontend`: SPA Angular/TypeScript.
- `openapi/v1`: contratto API versionato.
- `infra/localstack`: modello Terraform LocalStack per le esecuzioni locali production-like.
- `infra/modules`: moduli Terraform riutilizzabili per la futura infrastruttura AWS.
- `infra/aws`: placeholder per la futura baseline di prodotto su AWS reale.
- `docker`: immagini di runtime locali e configurazione dei servizi.
