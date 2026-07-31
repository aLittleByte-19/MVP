# ADR 0009: Pipeline asincrona delle comunicazioni e copertine su storage a oggetti

Status: Accepted, implemented
Date: 2026-07-27

## Context

La generazione di una comunicazione HR richiede due chiamate AI: il testo (pochi secondi) e
l'immagine di copertina (10-15 secondi, con picchi superiori). Sono latenze incompatibili con il
ciclo HTTP: `php-fpm` interrompe l'esecuzione dopo 30 secondi e la risposta resterebbe appesa per
tutta la durata. Le due chiamate hanno inoltre criticità diverse: senza testo non esiste una
comunicazione, senza copertina esiste eccome: e vanno trattate di conseguenza.

La copertina è un binario di qualche centinaio di kilobyte: va su storage a oggetti, non nel record
applicativo, che viene serializzato nello stato restituito da nove endpoint. È però un asset generato
dall'applicazione, non un documento HR: non condivide il disco dei documenti, che passa a S3 reale
quando serve Textract.

## Decision

La generazione diventa una pipeline Step Functions gemella di quella documentale
([ADR 0003](0003-sqs-instead-of-redis-queue.md), [ADR 0004](0004-localstack-terraform.md)), con tre
task a callback token: `communication.generate_text`, `communication.generate_cover`,
`communication.finalize`. `POST /api/v1/communications` risponde **202** con lo `streamUrl` e la SPA
segue l'avanzamento via SSE, ricevendo il testo appena pronto e la copertina quando arriva.

Il fallimento del testo fa fallire l'esecuzione. Il fallimento della copertina **non**: l'handler lo
registra su `cover_status`/`cover_error` e chiude il task con successo, così una comunicazione
valida non viene persa per un'immagine mancante. Il ramo `Catch → MarkCoverDegraded → Finalize`
dell'ASL copre timeout e worker caduti, perché nessun record resti in `processing`.

Le comunicazioni hanno **coda e DLQ dedicate** con un worker dedicato: una generazione immagini lenta
non deve occupare i consumatori della pipeline documentale, e ogni dominio ha i propri segnali di
backlog e la propria DLQ.

Claim atomico, deduplica sul token, heartbeat e callback vivono in `WorkflowTaskRunner`, condiviso da
entrambe le pipeline tramite un registry di handler per task type; la tabella `workflow_tasks` è
polimorfica sul soggetto. `app/Mvp/Workflow` contiene solo l'infrastruttura comune: i servizi di
un flusso stanno nella cartella del flusso.

Le copertine sono scritte su un disco proprio (`MVP_COMMUNICATION_COVER_DISK`, l'S3 emulato per default) sotto `MVP_COMMUNICATION_COVER_PREFIX` e servite da
`GET /api/v1/communications/{communication}/cover-image`, endpoint autorizzato per tenant che
streamma l'oggetto; lo stato applicativo espone solo l'URL relativo. Il percorso non cambia quando la
copertina viene sostituita, quindi l'URL porta una versione derivata dalla chiave dell'oggetto: senza,
il browser continuerebbe a mostrare l'immagine precedente presa dalla propria cache.

Il PDF finale impaginato segue la stessa logica: è un altro asset derivato, va sullo stesso disco sotto
un prefisso proprio (`MVP_COMMUNICATION_PDF_PREFIX`). A differenza della copertina però è
**interamente ricostruibile** dal record applicativo, quindi la chiave non è un UUID ma l'impronta del
contenuto renderizzato. Ne discende un'invalidazione implicita: cambiando titolo, corpo o copertina
cambia l'impronta e nasce un oggetto nuovo, senza che i servizi che modificano una comunicazione
debbano conoscere l'esistenza della cache. La stessa impronta fa da `ETag` e risparmia anche la
lettura da storage quando il client ha già il documento.

Questa cache è un'ottimizzazione, non una dipendenza: se il disco non è raggiungibile il PDF viene
rigenerato a ogni richiesta e l'errore finisce in `report()`. Non è un fallback nel senso vietato
dall'ADR 0005: l'output non è un dato sostitutivo, è lo stesso identico documento.

## Consequences

- La SPA deve gestire uno stato di avanzamento: la bozza si popola per gradi, prima il testo poi la
  copertina.
- Ogni chiave nuova (`COMMUNICATION_PIPELINE_*`) è obbligatoria in `RuntimeConfigurationLoader`:
  dopo un aggiornamento serve `make refresh-runtime`.
- Il numero di worker da scalare raddoppia (`make workers` agisce su entrambi).
- Una copertina degradata è un esito atteso: l'alert scatta solo oltre tre degradi in trenta minuti.
- Gli oggetti di copertina vanno rimossi insieme alla comunicazione e alla sostituzione, non essendoci
  vincolo di integrità fra database e storage.
- I PDF materializzati non vanno rimossi attivamente: sono ricostruibili e l'impronta li rende inerti.
  Restano però a occupare spazio dopo ogni modifica, quindi il prefisso è separato e svuotabile in blocco.
- Una modifica al template Blade, al watermark o al piè di pagina non è visibile all'impronta: va
  accompagnata dall'incremento di `CommunicationPdfService::RENDER_VERSION`, altrimenti i PDF già
  scritti continuano a essere serviti con il layout precedente.

## Alternatives considered

- **Generazione sincrona nella request HTTP**: scartata perché supera i limiti di `php-fpm` e perde il
  testo già generato quando la copertina rallenta.
- **Copertina come data URL nel record**: scartata perché il campo finisce nello stato restituito da
  nove endpoint, incluso lo storico a dieci elementi, moltiplicando il payload di ogni risposta.
- **Job Laravel su `queue:work`**: scartata per coerenza con [ADR 0003](0003-sqs-instead-of-redis-queue.md);
  lo stato del workflow resta fuori dall'esecutore.
- **Copertina come step opzionale della state machine documentale**: scartata perché unisce due domini
  con cicli di vita, timeout e criticità diversi.
- **URL presigned S3 verso la SPA**: scartata perché esporrebbe il bucket e sposterebbe il controllo
  del tenant fuori dall'applicazione, a differenza dell'anteprima documentale già esistente.
- **Coda condivisa con routing per task type**: scartata perché unisce il destino dei due flussi, con
  DLQ mista e segnali di backlog ambigui.

## Implementation evidence

- ASL: `infra/localstack/state-machines/communication-pipeline.asl.json`.
- Infrastruttura: `infra/localstack/main.tf` (`aws_sqs_queue.communications` + DLQ, state machine,
  IAM, regola EventBridge dedicata), servizio `queue-communications` in `docker-compose.yml`.
- Orchestrazione comune: `app/Mvp/Workflow/Contracts/WorkflowTaskHandler.php`,
  `app/Mvp/Workflow/Services/WorkflowTaskRegistry.php`, `WorkflowTaskRunner.php`,
  `app/Mvp/Workflow/Support/WorkflowContext.php`, migrazione `workflow_tasks`.
- Dominio: `app/Mvp/Communications/Services/` (workflow service, task handler, cover service),
  `app/Http/Controllers/Api/V1/CommunicationController.php`.
- Osservabilità: `docker/grafana/dashboards/communication-pipeline.json`,
  `docker/prometheus/rules/communication-alerts.yml`.

## References

- AWS Step Functions: callback con task token: https://docs.aws.amazon.com/step-functions/latest/dg/connect-to-resource.html
- AWS Well-Architected: Reliability Pillar (bulkhead e isolamento dei guasti): https://docs.aws.amazon.com/wellarchitected/latest/reliability-pillar/welcome.html
- Amazon Bedrock: modelli immagine: https://docs.aws.amazon.com/bedrock/latest/userguide/models-supported.html

## Related documents

- [`0003-sqs-instead-of-redis-queue.md`](0003-sqs-instead-of-redis-queue.md)
- [`0005-no-automatic-fallbacks.md`](0005-no-automatic-fallbacks.md)
- [`0006-observability-and-audit.md`](0006-observability-and-audit.md)
- [`../runbooks/communication-pipeline.md`](../runbooks/communication-pipeline.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§6.1, §9)
