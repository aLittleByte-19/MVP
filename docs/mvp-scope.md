# Perimetro funzionale

Il progetto copre i flussi principali di Document Intelligence per comunicazioni HR e analisi documentale. L'ambiente locale usa LocalStack e Terraform per modellare le dipendenze AWS-like in modo ripetibile.

Ogni area distingue quattro livelli:

- **Incluso**: presente e funzionante nella MVP.
- **Parziale**: presente in forma ridotta rispetto al prodotto finale.
- **Fuori scope MVP**: previsto per il prodotto finale, ma non implementato qui.
- **Evoluzione futura**: direzione di sviluppo successiva, non richiesta dal perimetro MVP.

Per lo stato implementativo di dettaglio (con evidenze nei path) il riferimento è
[`IMPLEMENTATION_OVERVIEW.md`](IMPLEMENTATION_OVERVIEW.md); questo documento ne è il
complemento funzionale e non deve contraddirlo.

## AI Assistant Generativo

Incluso:

- generazione di una bozza a partire da prompt, tono e stile, elaborata da una pipeline asincrona con avanzamento in tempo reale;
- validazione del prompt;
- persistenza della bozza generata (stato `draft`);
- anteprima di titolo e testo in sola lettura;
- immagine di copertina generata dall'AI, con sostituzione manuale e rimozione;
- storico delle generazioni con riapertura dell'anteprima di una bozza selezionata;
- metriche operative di base (contenuti generati, bozze, stato della generazione e delle copertine);
- anteprima del documento finale impaginato, con marcatore di trasparenza "Creato da AI Assistant";
- esportazione del documento finale in PDF, con lo stesso marcatore di trasparenza.

Parziale:

- lo storico elenca le ultime generazioni ma non offre i filtri avanzati (parola chiave, tono, stile, data).

Fuori scope MVP:

- modifica manuale persistente di titolo e testo;
- rigenerazione, annullamento modifiche e scarto della bozza;
- rating 1–5 con commento, preferiti e relativi feedback;
- salvataggio e riuso di una configurazione di prompt etichettata;
- dashboard analista (rating medio, statistiche di utilizzo, filtri).

La generazione usa il servizio AI configurato. Errori di configurazione, credenziali o modello vengono esposti come errori applicativi, senza contenuti sostitutivi. Il testo e la copertina hanno criticità diverse: se il testo non viene generato la bozza è fallita, mentre una copertina non disponibile viene segnalata all'operatore e lascia la comunicazione valida.

## AI Co-Pilot Documentale

Incluso:

- upload singolo di PDF;
- controllo formato e duplicato tramite hash;
- avvio asincrono tramite state machine Step Functions (emulata in LocalStack) con task pubblicati su SQS tramite callback task token, consumati dal worker `mvp:workflow:consume --queue=documents`;
- classificazione e split per destinatario tramite Bedrock sul testo OCR (qualsiasi tipologia di documento, sempre almeno un destinatario);
- estrazione dei campi principali tramite Bedrock sul testo OCR (nome/cognome, azienda, data, tipologia, descrizione);
- confidenza calcolata oggettivamente come leggibilità OCR (Textract) ponderata sulla completezza dei campi chiave, non come auto-valutazione del modello;
- persistenza di documento originale, sotto-documenti e dati estratti;
- dettaglio documento affiancato (anteprima a sinistra, dati estratti a destra);
- correzione manuale dei campi estratti e validazione manuale (human-in-the-loop);
- stati di revisione del sotto-documento (`needs_review`, `auto_validated`, `quarantined`, `manually_validated`);
- preview PDF del sotto-documento con gestione esplicita dell'errore (risposta applicativa leggibile) quando lo storage non è raggiungibile o il file manca;
- messaggio di invio precompilato (destinatario, oggetto, testo) calcolato dai dati estratti, con anteprima ed esportazione PDF (UC-48/48.1/48.2/48.3);
- stato `failed` esplicito quando split o estrazione non riescono;
- metriche operative su documenti elaborati e soglie di confidenza.

Parziale:

- lo storico dei documenti analizzati è ordinato dal più recente ma non offre i filtri avanzati (ricerca per destinatario/azienda, soglia di confidenza, mese e anno).

Fuori scope MVP:

- invio effettivo del messaggio precompilato e relativo "stato invio" (`Inviato`/`Non inviato`): la colonna `sub_documents.send_status` e l'identità SES Terraform esistono ma non c'è codice di invio; resta esclusa anche la modifica dei campi destinatario/oggetto/testo (UC-49/50/51/52);
- campi estratti email destinatario, codice fiscale e matricola dipendente;
- classificazione manuale iniziale in upload;
- metriche e dashboard sugli invii.

## Observability e Sicurezza Operativa

Incluso:

- request ID e correlation ID su risposte HTTP e log;
- audit trail append-only per azioni rilevanti;
- metriche HTTP golden-signal e di dominio in formato Prometheus;
- OpenTelemetry Collector come unico gateway locale (metriche verso Prometheus, trace verso Tempo);
- raccolta log dei container via Grafana Alloy verso Loki;
- 6 dashboard Grafana provisionate (`api-golden-signals`, `document-pipeline`, `communication-pipeline`, `ai-ocr-quality`, `queues-and-dlq`, `logs-and-errors`);
- 15 alert rule Prometheus su error ratio, latenza, readiness, stato worker, code/DLQ per dominio, esecuzioni Step Functions, generazioni bloccate e degrado delle copertine, collegate a runbook dedicati;
- contract OpenAPI 3.1 come fonte del client frontend, verificato in CI;
- blocco runtime delle superfici non appartenenti alla SPA/API.

## Esclusioni trasversali

### Fuori scope MVP

- identity provider reale e policy RBAC/ABAC complete (l'identità è simulata dal middleware `mvp.identity`);
- integrazione SES per invio effettivo (l'identità SES Terraform esiste, ma non c'è codice di invio);
- bus eventi EventBridge per gli eventi terminali della pipeline (bus, rule e target verso SQS esistono in Terraform, ma l'applicativo non pubblica né consuma eventi: nessun `PutEvents`);
- contract OpenAPI completo per ogni evento operativo interno (il contratto copre le API applicative, non gli eventi di dominio interni della pipeline).

### Evoluzione futura

- SLO/error budget formalizzati e receiver di notifica reali per Alertmanager (oggi soglie statiche e routing demo);
- backend di osservabilità enterprise e retention dichiarate per metriche/trace/log;
- propagazione del trace context attraverso SQS/Step Functions.
