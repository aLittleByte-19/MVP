# Struttura del repository

Il repository mantiene le convenzioni Laravel, separando i confini di runtime dalla logica di
dominio.

- `app/Copilot`: codice applicativo di dominio del co-pilot AI.
- `app/Copilot/Ai`: integrazione Bedrock e servizi specifici dell'AI.
- `app/Copilot/Audit`: servizi di audit logging.
- `app/Copilot/Documents`: enum ed elaborazione dei documenti.
- `app/Copilot/Communications`: enum delle comunicazioni e relativi servizi.
- `app/Copilot/Identity`: identità utente risolta a runtime.
- `app/Copilot/Observability`: exporter Prometheus e registrazione delle metriche.
- `app/Copilot/Ocr`: integrazione OCR Textract.
- `app/Copilot/Workflow`: servizi di orchestrazione del workflow Step Functions/SQS.
- `app/Console/Commands`: comandi artisan, incluso il worker `poc:workflow:consume`.
- `app/Http`: controller HTTP, middleware e validazione delle richieste.
- `app/Models/Copilot`: model Eloquent del dominio PoC.
- `apps/frontend`: SPA React/Vite.
- `openapi/v1`: contratto API versionato.
- `infra/localstack`: modello Terraform LocalStack per le esecuzioni locali production-like.
- `infra/modules`: moduli Terraform riutilizzabili per la futura infrastruttura AWS.
- `infra/aws`: placeholder per la futura baseline di prodotto su AWS reale.
- `docker`: immagini di runtime locali e configurazione dei servizi.
