# Perimetro funzionale

Il progetto copre i flussi principali di Document Intelligence per comunicazioni HR e analisi documentale. L'ambiente locale usa LocalStack e Terraform per modellare le dipendenze AWS-like in modo ripetibile.

## AI Assistant Generativo

Sono inclusi:

- generazione di una bozza a partire da prompt, tono e stile;
- validazione del prompt;
- anteprima di titolo e testo;
- modifica manuale di titolo e testo;
- salvataggio, approvazione, scarto e storico delle generazioni;
- rating e commento opzionale;
- metriche operative sulle generazioni.

La generazione usa il servizio AI configurato. Errori di configurazione, credenziali o modello vengono esposti come errori applicativi, senza contenuti sostitutivi.

## AI Co-Pilot Documentale

Sono inclusi:

- upload singolo di PDF;
- controllo formato e duplicato tramite hash;
- avvio asincrono via Laravel Queue su SQS;
- split documentale tramite Bedrock;
- estrazione dei campi principali tramite Bedrock;
- persistenza di documento originale, sotto-documenti e dati estratti;
- preview PDF del sotto-documento;
- stato `failed` esplicito quando split o estrazione non riescono;
- storico e filtri principali;
- metriche operative su documenti elaborati e soglie di confidenza.

## Esclusioni

Non sono ancora inclusi:

- identity provider reale e policy RBAC/ABAC complete;
- audit trail append-only completo;
- workflow Step Functions collegato end-to-end al worker Laravel;
- integrazione SES per invio effettivo;
- OpenTelemetry collector e dashboard metriche;
- contract OpenAPI completo.
