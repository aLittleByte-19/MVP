# NEXUM / aLittleByte - Document Intelligence PoC

Questa Proof of Concept (PoC) dimostra l'integrazione di intelligenza artificiale generativa e documentale all'interno dell'ecosistema NEXUM. Il prototipo abilita l'automazione di due processi critici: la generazione assistita di contenuti e l'elaborazione intelligente di flussi documentali (Co-Pilot CdL).

## Stack Tecnologico & Motivazioni

L'architettura è stata progettata per essere scalabile, modulare e facilmente integrabile in ambienti enterprise.

*   **Backend: Laravel 12**
    *   *Perché:* Garantisce robustezza, sicurezza e una gestione eccellente di code (Queues) e task asincroni, fondamentali per l'elaborazione documentale.
*   **Interfaccia: Blade + CSS/JS custom**
    *   *Perché:* Permette di mantenere un flusso applicativo guidato e aderente alla UX della PoC, senza introdurre un pannello gestionale separato.
*   **AI Engine: Amazon Bedrock (Model: Nova / Anthropic Claude)**
    *   *Perché:* Offre l'accesso a modelli LLM state-of-the-art tramite API serverless. La PoC sfrutta le capacità native di analisi dei documenti (PDF) per evitare complessi passaggi di pre-elaborazione.
*   **Infrastruttura: Docker & Cloud Native Stack**
    *   **PostgreSQL:** Database relazionale per la persistenza dei dati strutturati.
    *   **Redis:** Gestione delle code di lavorazione e caching.
    *   **MinIO:** Storage compatibile con lo standard S3 per la gestione sicura dei documenti originali e dei relativi split.

## Flusso di Lavoro (Workflow)

L'applicazione implementa una pipeline di elaborazione asincrona e reattiva:

### 1. Document Intelligence (Co-Pilot CdL)
*   **Ingestion:** Il caricamento di documenti PDF massivi avviene tramite l'interfaccia pubblica. Il file viene immediatamente persistito su storage S3-compatible (MinIO).
*   **Elaborazione in Coda:** Un job Laravel (`ProcessOriginalDocumentJob`) gestisce l'intero ciclo di vita del documento in background tramite Redis.
*   **Analisi & Split Logico (Bedrock):** Il backend interroga Amazon Bedrock inviando il PDF originale. L'AI identifica logicamente i confini dei singoli documenti e i relativi destinatari.
*   **Frammentazione Fisica:** Utilizzando la libreria **FPDI**, il sistema seziona fisicamente il PDF originale in file indipendenti salvati nello storage.
*   **Estrazione Sequenziale:** Ogni frammento viene rielaborato singolarmente da Bedrock per estrarre metadati strutturati e calcolare il **Confidence Score**.
*   **Real-time Streaming (SSE):** Il frontend rimane in ascolto su un endpoint **Server-Sent Events**. Man mano che ogni sotto-documento viene completato, l'interfaccia si aggiorna dinamicamente senza ricaricamento.

### 2. AI Content Assistant
*   **Prompt Engineering:** L'utente fornisce i parametri di input (testo, tono, stile). Il sistema applica dei template di sistema per contestualizzare la richiesta ad Amazon Bedrock.
*   **Generazione Bozza:** L'LLM restituisce un oggetto JSON strutturato. Il backend lo trasforma in una risorsa `Communication` in stato "Bozza", pronta per essere editata o finalizzata dall'utente.

## Organizzazione del Codice

Il codice specifico della PoC è raccolto sotto `app/Poc`, in modo da separarlo dalla struttura Laravel di base e rendere più chiaro dove intervenire:

*   `app/Poc/Controllers`: controller HTTP dell'applicativo e della configurazione PoC.
*   `app/Poc/Models`: modelli Eloquent legati ai documenti, ai dati estratti e alle comunicazioni.
*   `app/Poc/Services`: logica applicativa riusabile, integrazione Bedrock e pipeline documentale.
*   `app/Poc/Jobs`: job asincroni usati dalla coda Redis.
*   `app/Poc/Enums`: stati e valori enumerati condivisi tra modelli, servizi e test.
*   `app/Poc/Commands`: comandi operativi dedicati alla PoC.

Le view custom sono raccolte direttamente in `resources/views/poc`, mentre gli asset statici della UI sono in `public/poc`.


## Amministrazione & Configurazione (Dashboard)

L'applicativo include un'area di amministrazione Blade dedicata al percorso `/admin`, denominata **Amministrazione PoC**. A differenza di un back-office tradizionale, questa dashboard funge da centro di controllo operativo per il comportamento dell'intelligenza artificiale e del runtime di sistema.

*   **Punto di Accesso:** `http://localhost:8080/admin`
*   **Configurazione Dinamica AI:** Permette di modificare in tempo reale i driver di elaborazione (passando da simulazione a Bedrock/Textract reale), impostare i modelli LLM (es. Amazon Nova) e variare le soglie di confidenza per l'estrazione dati.
*   **Gestione Credenziali:** Interfaccia sicura per l'inserimento e la verifica delle chiavi IAM AWS, con feedback immediato sullo stato della connessione.
*   **Controllo Runtime:** Funzionalità integrate per il riavvio della coda Redis, la pulizia della cache di sistema e il reset completo dei dati di elaborazione per scopi di demo.
*   **Persistenza:** Ogni modifica effettuata nella dashboard viene scritta direttamente nel file `.env`, rendendo le configurazioni persistenti anche dopo il riavvio dei container.

## Installazione Rapida

1.  **Configurazione Ambiente:**
    ```bash
    cp .env.example .env
    ```
2.  **Avvio Stack:**
    ```bash
    docker compose up -d --build
    ```
3.  **Accesso:**
    *   Applicazione: `http://localhost:8080`
    *   MinIO Console: `http://localhost:9001`

## Configurazione AI

Il sistema supporta driver `fake` per test locali senza costi e driver `real` per integrazione con AWS:

```env
AI_GENERATOR_DRIVER=bedrock  # o fake
DOCUMENT_OCR_DRIVER=local    # o textract
BEDROCK_ENABLED=true
BEDROCK_MODEL_ID=amazon.nova-lite-v1:0
```

*Nota: Le credenziali IAM per Bedrock sono gestite tramite variabili d'ambiente standard AWS.*

## Obiettivi della PoC
Dimostrare la fattibilità tecnica di:
*   Riduzione del 90% del tempo di data-entry manuale tramite OCR/AI.
*   Automazione dello splitting di documenti massivi multi-destinatario.
*   Standardizzazione della qualità delle comunicazioni aziendali tramite AI generativa.
