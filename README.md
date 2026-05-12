# NEXUM / aLittleByte PoC

Proof of Concept universitaria per sperimentare due funzioni centrali della piattaforma NEXUM/aLittleByte:

- generazione assistita di contenuti tramite AI Assistant;
- caricamento e analisi iniziale di documenti PDF per il Co-Pilot CdL.

L'applicazione e' pensata per una demo locale semplice: una singola interfaccia web pubblica, senza login, raggiungibile dalla root del progetto avviato in Docker.

## Stack locale

- Laravel 12 come backend applicativo.
- PostgreSQL come database.
- Redis e Laravel Queue per lavorazioni asincrone.
- Nginx e PHP-FPM per servire l'applicativo.
- MinIO come storage S3-compatible locale.
- Mailpit per eventuali test email locali.
- AI worker FastAPI opzionale per OCR/parsing/split documentale.
- Amazon Bedrock e Textract configurabili tramite variabili d'ambiente.

## Funzioni disponibili

### AI Assistant

La sezione `AI Assistant > Generazione` permette di:

- inserire un prompt testuale;
- selezionare tono e stile;
- generare una bozza composta da titolo e testo;
- visualizzare e revisionare localmente il risultato.

Di default la generazione usa un driver fake deterministico, utile per provare il flusso senza credenziali AWS. Se Bedrock viene configurato, la stessa interfaccia puo' inviare la richiesta al servizio reale.

### Co-Pilot CdL

La sezione `Co-Pilot CdL > Caricamento` permette di:

- caricare un PDF;
- salvare il documento nel sistema;
- avviare una prima elaborazione di split;
- rilevare i campi principali del documento;
- consultare lo storico dei documenti rilevati;
- aprire il dettaglio con campi OCR, anteprima dello split e preview PDF.

La PoC non implementa ancora una pipeline documentale completa: split robusto, OCR avanzato, matching destinatario e revisione human-in-the-loop sono predisposti come punti di evoluzione.

## Avvio rapido

Copia la configurazione di esempio:

```bash
cp .env.example .env
```

Avvia lo stack locale:

```bash
docker compose up -d --build
```

Al primo avvio il container `app`:

- installa le dipendenze Composer se necessario;
- genera `APP_KEY` se manca;
- esegue le migrazioni;
- prepara l'applicazione per l'uso locale.

Quando i container sono attivi, apri:

```text
http://localhost:8080/
```

Servizi di supporto:

- MinIO console: `http://localhost:9001`
- Mailpit: `http://localhost:8025`
- AI worker healthcheck: `http://localhost:8001/health`

## Comandi utili

Eseguire le migrazioni manualmente:

```bash
docker compose exec app php artisan migrate
```

Lanciare i test:

```bash
docker compose exec app php artisan test
```

Pulire cache Laravel:

```bash
docker compose exec app php artisan optimize:clear
```

Rigenerare autoload Composer:

```bash
docker compose exec app composer dump-autoload
```

## Configurazione AI

La configurazione locale usa valori sicuri per la demo:

```env
AI_GENERATOR_DRIVER=fake
DOCUMENT_OCR_DRIVER=local
DOCUMENT_CLASSIFIER_DRIVER=fake
BEDROCK_ENABLED=false
TEXTRACT_ENABLED=false
```

Per provare Amazon Bedrock reale con credenziali IAM temporanee, abilita il servizio e imposta modello, region e credenziali tramite `.env` locale o variabili d'ambiente non versionate:

```env
BEDROCK_ENABLED=true
BEDROCK_MODEL_ID=eu.anthropic.claude-sonnet-4-20250514-v1:0
AWS_DEFAULT_REGION=eu-north-1
AWS_ACCESS_KEY_ID=<access-key>
AWS_SECRET_ACCESS_KEY=<secret-key>
AWS_SESSION_TOKEN=<session-token-se-temporaneo>
```

Per i modelli che supportano solo `INFERENCE_PROFILE`, `BEDROCK_MODEL_ID` deve contenere l'ID del profilo di inferenza, non il foundation model id. Le credenziali generate dal portale AWS IAM Identity Center sono temporanee: quando scadono vanno sostituite nel file `.env` locale o riesportate nell'ambiente. Per Textract valgono le stesse cautele: le credenziali AWS e gli ARN reali non devono essere versionati.

## Storage locale

Per default lo storage usa MinIO con API S3-compatible:

```env
FILESYSTEM_DISK=s3
AWS_ENDPOINT=http://minio:9000
AWS_BUCKET=nexum-local
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Il servizio `minio-init` crea automaticamente il bucket locale configurato da `MINIO_BUCKET`.

## Note operative

- L'applicazione corrente funziona senza autenticazione.
- L'interfaccia principale e' servita solo da `/`.
- Le API della PoC sono sotto `/poc/api/*`.
- I task asincroni usano Redis tramite il servizio `queue`.
- Se il browser mostra asset vecchi, ricarica forzatamente la pagina: CSS e JS sono comunque versionati automaticamente in base alla modifica dei file.

## Limiti intenzionali

Questa PoC dimostra i flussi principali, non una soluzione enterprise completa. Sono volutamente lasciati come evoluzioni successive:

- autenticazione e gestione ruoli;
- workflow completo di approvazione;
- invio email reale;
- OCR/Textract completo;
- split documentale avanzato;
- matching destinatario;
- metriche avanzate e audit trail esteso.
