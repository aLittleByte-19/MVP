# Perimetro funzionale

Il progetto copre i flussi principali di Document Intelligence per comunicazioni HR e analisi
documentale. L'ambiente locale usa LocalStack e Terraform per modellare le dipendenze AWS-like in
modo ripetibile.

Ogni area distingue quattro livelli:

- **Incluso**: presente e funzionante nella MVP.
- **In corso**: assegnato e pianificato, non ancora implementato.
- **Fuori scope MVP**: deliberatamente escluso dal perimetro concordato con il committente.
- **Evoluzione futura**: direzione di sviluppo successiva, non richiesta dal perimetro MVP.

Per lo stato implementativo di dettaglio (con evidenze nei path) il riferimento è
[`IMPLEMENTATION_OVERVIEW.md`](IMPLEMENTATION_OVERVIEW.md); questo documento ne è il complemento
funzionale e non deve contraddirlo.

> Il perimetro qui descritto recepisce le indicazioni del committente del 15/07/2026: non sono
> richiesti deploy reale, autenticazione degli utenti né invio effettivo delle comunicazioni;
> l'obiettivo principale dichiarato è **la corretta identificazione del destinatario**.

## AI Assistant Generativo

Incluso:

- generazione di una bozza a partire da prompt, tono e stile, elaborata da una pipeline asincrona
  con avanzamento in tempo reale;
- validazione del prompt;
- persistenza della bozza generata (stato `draft`);
- immagine di copertina generata dall'AI, con sostituzione manuale e rimozione;
- storico dei soli contenuti salvati (stato "Approvata"), con riapertura dell'anteprima di una
  voce selezionata; una bozza non ancora salvata non vi compare (UC-9);
- filtri dello storico per parola chiave, tono, stile e data (UC-15..UC-18);
- modifica manuale persistente di titolo e testo della bozza, con annullamento prima del
  salvataggio; consentita finché la bozza non è stata scartata;
- richiesta di una nuova variante della bozza corrente, che sostituisce testo e copertina
  mantenendo prompt, tono e stile (UC-6); consentita anche dopo il salvataggio in storico;
- salvataggio esplicito della bozza nello storico (UC-9): solo a questo punto entra nello
  storico (stato "Approvata"); resta comunque modificabile e rigenerabile come prima;
- scarto della bozza corrente con conferma, che la esclude dallo storico attivo mantenendola
  tracciata come "Scartata" (UC-7);
- eliminazione definitiva di un elemento dello storico, con conferma (UC-23);
- salvataggio della configurazione corrente del prompt (testo, tono, stile) come preset
  riutilizzabile, con nome libero; se il nome è vuoto o già in uso per il tenant, il sistema
  assegna un'etichetta progressiva ("Senza nome (1)", "(2)", ...); i preset compaiono nello
  storico contenuti, filtrabili con gli stessi criteri delle bozze salvate (UC-19);
- riutilizzo di un preset salvato, che precompila il form senza avviare una nuova generazione;
  non applicabile a una bozza già salvata, per cui restano modifica e rigenerazione diretta;
- valutazione 1-5 stelle con commento qualitativo opzionale, una sola per generazione;
- anteprima del documento finale impaginato, con marcatore di trasparenza "Creato da AI Assistant";
- esportazione del documento finale in PDF, con lo stesso marcatore di trasparenza;
- metriche operative (contenuti generati, bozze, stato della generazione e delle copertine,
  valutazioni ricevute, media stelle);
- aggiunta e rimozione di una generazione dai preferiti (UC-21, UC-22).

Fuori scope MVP:

- dashboard analista dedicata (statistiche di utilizzo aggregate oltre alle metriche operative);
- flusso di approvazione della bozza. Lo stato `approved` esiste nell'enum `CommunicationStatus`
  e nel vincolo CHECK in migrazione come **predisposizione documentata**, sullo stesso modello
  dell'identità SES: non ha endpoint, interfaccia né transizione, e non va scambiato per un ramo
  dimenticato. Il ciclo previsto oggi è bozza → modifica/rigenerazione → scarto o esportazione.

La generazione usa il servizio AI configurato. Errori di configurazione, credenziali o modello
vengono esposti come errori applicativi, senza contenuti sostitutivi. Il testo e la copertina hanno
criticità diverse: se il testo non viene generato la bozza è fallita, mentre una copertina non
disponibile viene segnalata all'operatore e lascia la comunicazione valida.

## AI Co-Pilot Documentale

Incluso:

- upload singolo di PDF;
- controllo formato e duplicato tramite hash;
- avvio asincrono tramite state machine Step Functions (emulata in LocalStack) con task pubblicati
  su SQS tramite callback task token, consumati dal worker `mvp:workflow:consume --queue=documents`;
- classificazione e split per destinatario tramite Bedrock sul testo OCR (qualsiasi tipologia di
  documento, sempre almeno un destinatario);
- estrazione dei campi principali tramite Bedrock sul testo OCR (nome/cognome, azienda, data,
  tipologia, descrizione);
- confidenza calcolata oggettivamente come leggibilità OCR (Textract) ponderata sulla completezza
  dei campi chiave, non come auto-valutazione del modello;
- persistenza di documento originale, sotto-documenti e dati estratti;
- dettaglio documento affiancato (anteprima a sinistra, dati estratti a destra);
- correzione manuale dei campi estratti e validazione manuale (human-in-the-loop), con errori di
  validazione riportati per singolo campo;
- campi destinatario editabili e persistiti: email destinatario (con validazione formato), codice
  fiscale (con validazione del carattere di controllo) e matricola dipendente;
- stati di revisione del sotto-documento (`needs_review`, `auto_validated`, `quarantined`,
  `manually_validated`);
- preview PDF del sotto-documento con gestione esplicita dell'errore (risposta applicativa
  leggibile) quando lo storage non è raggiungibile o il file manca;
- messaggio di invio precompilato (destinatario, oggetto, testo) calcolato dai dati estratti, con
  anteprima ed esportazione PDF (UC-48/48.1/48.2/48.3) e correzione manuale dei tre campi;
- filtri dello storico documenti applicati lato API, con scoping sul tenant: ricerca per
  nome/cognome/azienda (UC-35), stato di invio (UC-36), soglia di confidenza sopra o sotto un
  valore (UC-37), mese e anno del documento (UC-38);
- stato `failed` esplicito quando split o estrazione non riescono;
- metriche operative su documenti elaborati, soglie di confidenza e stato di invio.

In corso:

- visualizzazione dell'email destinatario e della data/ora di caricamento nel dettaglio
  (UC-39.12, UC-39.15);
- classificazione e metadati manuali in fase di upload (UC-32).

Fuori scope MVP:

- **invio del messaggio dall'interno della piattaforma**. Il recapito avviene tramite canali terzi:
  il documento si esporta in PDF e si invia fuori dal prodotto. Di conseguenza
  `sub_documents.send_status` non rappresenta un invio effettuato dal sistema ma **l'avvenuto
  scaricamento del PDF**: la transizione `Da inviare` → `Inviato` scatta sul download
  (`GET /api/v1/documents/{subDocument}/send-export`), non sull'anteprima, ed è a senso unico;
- metriche e dashboard sugli invii reali (esiste invece la distribuzione per stato di scaricamento).

## Observability e Sicurezza Operativa

Incluso:

- request ID e correlation ID su risposte HTTP e log;
- audit trail append-only per azioni rilevanti, incluse valutazione della bozza e scaricamento del
  messaggio di invio;
- metriche HTTP golden-signal e di dominio in formato Prometheus;
- OpenTelemetry Collector come unico gateway locale (metriche verso Prometheus, trace verso Tempo);
- raccolta log dei container via Grafana Alloy verso Loki;
- 6 dashboard Grafana provisionate (`api-golden-signals`, `document-pipeline`,
  `communication-pipeline`, `ai-ocr-quality`, `queues-and-dlq`, `logs-and-errors`);
- 15 alert rule Prometheus su error ratio, latenza, readiness, stato worker, code/DLQ per dominio,
  esecuzioni Step Functions, generazioni bloccate e degrado delle copertine, collegate a runbook
  dedicati;
- contract OpenAPI 3.1 come fonte del client frontend, verificato in CI;
- blocco runtime delle superfici non appartenenti alla SPA/API.

## Esclusioni trasversali

### Fuori scope MVP

Le prime tre voci sono state escluse esplicitamente dal committente il 15/07/2026.

- **deploy reale**: lo stack resta quello emulato in locale (Docker, LocalStack, Terraform).
  I servizi AWS reali in uso restano S3, Textract e Bedrock; non se ne aggiungono altri;
- **autenticazione degli utenti**: nessun identity provider e nessuna modellazione di utenti
  effettivi. L'identità è simulata dal middleware `mvp.identity` e non va estesa;
- **invio effettivo delle comunicazioni** (vedi la sezione Co-Pilot per la ridefinizione di
  `send_status`);
- policy RBAC/ABAC complete;
- integrazione SES per invio effettivo: l'identità SES Terraform esiste come scaffolding
  documentato, ma non c'è né va aggiunto codice di invio;
- bus eventi EventBridge per gli eventi terminali della pipeline (bus, rule e target verso SQS
  esistono in Terraform, ma l'applicativo non pubblica né consuma eventi: nessun `PutEvents`);
- contract OpenAPI per ogni evento operativo interno (il contratto copre le API applicative, non
  gli eventi di dominio interni della pipeline).

### Evoluzione futura

- SLO/error budget formalizzati e receiver di notifica reali per Alertmanager (oggi soglie statiche
  e routing demo);
- backend di osservabilità enterprise e retention dichiarate per metriche/trace/log;
- propagazione del trace context attraverso SQS/Step Functions.
