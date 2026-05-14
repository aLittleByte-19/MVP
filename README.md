# NEXUM / aLittleByte - Document Intelligence PoC

<p align="center">
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/pint.yml"><img alt="Pint" src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/pint.yml?branch=main&label=Pint&style=flat-square"></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/pest.yml"><img alt="Pest" src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/pest.yml?branch=main&label=Pest&style=flat-square"></a>
  <a href="https://github.com/aLittleByte-19/PoC/actions/workflows/accessibility.yml"><img alt="Accessibility" src="https://img.shields.io/github/actions/workflow/status/aLittleByte-19/PoC/accessibility.yml?branch=main&label=A11y&style=flat-square"></a>
</p>

Proof of Concept per validare l'uso di AI generativa e AI documentale nei flussi NEXUM.

La PoC copre due casi d'uso:

* generazione assistita di comunicazioni HR;
* analisi di PDF multi-destinatario, divisione in sotto-documenti ed estrazione di dati strutturati.

La demo funziona in locale anche senza servizi AI reali. Quando serve una prova più realistica, può essere collegata ad Amazon Bedrock.

## Scope della PoC

La PoC serve a verificare:

* se un PDF con più destinatari può essere diviso automaticamente in documenti separati;
* se i dati principali dei documenti possono essere precompilati per ridurre il data-entry manuale;
* se un assistente AI può generare bozze di comunicazioni HR controllabili dall'utente;
* se l'esperienza locale è sufficientemente fluida per una demo end-to-end.

Il flusso è pensato per restare utilizzabile anche quando l'estrazione dei campi non riesce: il sotto-documento resta visibile e può essere verificato manualmente.

## Stack

* **Laravel 12** per backend, validazione, persistenza e code.
* **Blade + CSS/JS custom** per l'interfaccia della PoC.
* **Docker, PostgreSQL, Redis e MinIO** per l'ambiente locale.
* **Amazon Bedrock** opzionale per generazione contenuti, split documentale ed estrazione dati.

## Installazione Rapida

1. Crea il file ambiente:

   ```bash
   cp .env.example .env
   ```

2. Avvia lo stack:

   ```bash
   docker compose up -d --build
   ```

3. Apri i servizi:

   * Applicazione: `http://localhost:8080`
   * Admin PoC: `http://localhost:8080/admin`
   * MinIO Console: `http://localhost:9001`

L'entrypoint Docker installa le dipendenze, prepara Laravel e applica le migrazioni al primo avvio.

## Configurazione

La configurazione predefinita è adatta alla demo locale: Bedrock è disabilitato, lo split è simulato e l'estrazione OCR usa dati dimostrativi.

```env
BEDROCK_ENABLED=false
BEDROCK_MODEL_ID=amazon.nova-lite-v1:0
DOCUMENT_CLASSIFIER_DRIVER=fake
DOCUMENT_OCR_DRIVER=local
POC_CONFIDENCE_THRESHOLD=80
```

Per usare Bedrock reale:

```env
BEDROCK_ENABLED=true
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_SESSION_TOKEN=...
AWS_DEFAULT_REGION=eu-north-1
DOCUMENT_CLASSIFIER_DRIVER=bedrock
DOCUMENT_OCR_DRIVER=bedrock
```

Valori supportati:

* `DOCUMENT_CLASSIFIER_DRIVER`: `fake` oppure `bedrock`
* `DOCUMENT_OCR_DRIVER`: `local` oppure `bedrock`
* `BEDROCK_ENABLED`: `false` per simulazione, `true` per chiamate reali

Le stesse impostazioni possono essere gestite dalla dashboard admin:

```text
http://localhost:8080/admin
```

Da qui si possono cambiare modello Bedrock, driver documentali, soglia di confidenza, credenziali AWS e dati demo. Le impostazioni vengono salvate nel file `.env`.

Le variabili Textract sono presenti in `.env.example`, ma sono disattivate per questa PoC.

## Test

Per eseguire la suite:

```bash
make test
```

Comandi utili:

```bash
make fresh
make logs
docker compose exec -T app ./vendor/bin/pint --test
```

`make fresh` resetta database e dati generati dalla PoC. `make logs` mostra i log dei container principali.
