# Tracciabilità delle scelte tecnologiche rispetto al Capitolato

Questo documento raccoglie le scelte tecnologiche e architetturali della PoC e, per ciascuna,
il razionale ingegneristico che la motiva insieme al riscontro che trova nel Capitolato C5
(`[NEXUM] BRD-FASE02-2025`, v. 12). Le scelte nascono da criteri di progettazione —
semplicità, sicurezza, riproducibilità, osservabilità, disaccoppiamento — e si è verificato
che siano coerenti con i requisiti e i vincoli espressi dal Capitolato, qui citato verbatim
con indicazione della sezione di provenienza. Gli ADR in
[`docs/architecture-decisions/`](../architecture-decisions/) restano il razionale di dettaglio
e non vengono qui duplicati.

Dove la PoC adotta framework diversi da quelli ipotizzati nel Capitolato (Ruby on Rails,
Angular, Next.js), la scelta risponde a ragioni pratiche di ecosistema, maturità e velocità di
sviluppo, e rientra comunque nella libertà tecnologica esplicitamente prevista nella sezione
«Vincoli». Dove un servizio cloud è emulato in locale (LocalStack), si riporta la voce del
servizio AWS corrispondente: la PoC ne è la simulazione locale.

---

## 1. Framework backend (Laravel 12 / PHP 8.4)

**Scelta nella PoC:** API backend in Laravel 12 su PHP 8.4 ([`composer.json`](../../composer.json):
`"laravel/framework": "^12.0"`, `"php": "^8.4"`). È una scelta solida per una PoC: ecosistema
maturo con coda, validazione, policy di autorizzazione, migrazioni e SDK AWS già integrati,
che riduce il codice infrastrutturale e accelera lo sviluppo. Si discosta dal Ruby on Rails
ipotizzato restando nel perimetro consentito.

**Riscontro nel Capitolato:**
> «Utilizzo esclusivo di tecnologie open source o academic-friendly (o approvate dal team Eggon).»
> — Capitolato C5, sezione «Vincoli»

La voce di stack ipotizzata resta indicativa e non vincolante:
> «Ruby on Rails (API-first) su ECS Fargate (service stateless in più task), dietro Application Load Balancer (ALB) con AWS WAF.»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

**ADR correlato:** [0002 - Laravel API JSON](../architecture-decisions/0002-laravel-api-json.md)

---

## 2. Frontend SPA (React + TypeScript + Vite)

**Scelta nella PoC:** SPA React 19 + TypeScript + Vite in [`apps/frontend/`](../../apps/frontend/)
([`package.json`](../../apps/frontend/package.json): `react ^19.0.0`, `vite ^6.0.0`,
`typescript ^5.7.0`). Un singolo frontend tipizzato con tooling rapido (Vite) e client API
generato da OpenAPI mantiene la dashboard semplice, testabile e allineata al contratto; evita
la complessità di gestire due framework distinti per dashboard e PWA in fase di prototipo.

**Riscontro nel Capitolato:**
> «Utilizzo esclusivo di tecnologie open source o academic-friendly (o approvate dal team Eggon).»
> — Capitolato C5, sezione «Vincoli»

Il requisito di doppia superficie (dashboard/PWA) resta pienamente soddisfacibile:
> «Le funzionalità dovranno essere disponibili, a seconda dei destinatari, sulla dashboard o sulla PWA.»
> — Capitolato C5, sezione «Requisiti di Business»

Le voci di stack ipotizzate restano indicative:
> «Dashboard amministrativa (Angular): build statico su S3; distribuzione via CloudFront (cache, HTTPS, geodistribuzione).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

**ADR correlato:** [0001 - Frontend SPA](../architecture-decisions/0001-frontend-spa.md)

---

## 3. API versionata e integrabile con NEXUM (JSON `/api/v1`)

**Scelta nella PoC:** contratto JSON esposto solo sotto `/api/v1`, generato da OpenAPI
([`openapi/v1/`](../../openapi/v1/)), con autorizzazione server-side e error envelope
centralizzato. Un contratto versionato e descritto formalmente dà stabilità, abilita un client
tipizzato e separa nettamente frontend e backend: la base naturale per integrarsi con un
ecosistema esistente.

**Riscontro nel Capitolato:**
> «Ogni modulo dovrà essere integrabile o compatibile con l'infrastruttura di NEXUM (backend, frontend, database, architettura)»
> — Capitolato C5, sezione «Requisiti di Business»

> «Ogni team avrà accesso alla documentazione e alle API NEXUM.»
> — Capitolato C5, sezione «Assunzioni del Progetto»

**ADR correlato:** [0002 - Laravel API JSON](../architecture-decisions/0002-laravel-api-json.md)

---

## 4. Code asincrone su SQS

**Scelta nella PoC:** driver coda predefinito SQS ([`config/queue.php`](../../config/queue.php):
`'default' => env('QUEUE_CONNECTION', 'sqs')`), con DLQ e worker dedicato per la pipeline
documentale. Disaccoppiare la richiesta HTTP dall'elaborazione lunga è la scelta corretta per
una pipeline documentale: retry e dead-letter nativi, worker scalabili orizzontalmente e
fallimenti isolati e osservabili. Il job runner ipotizzato (Sidekiq, Ruby) è sostituito dal
worker queue di Laravel, coerente con la scelta di backend (§1).

**Riscontro nel Capitolato:**
> «Background jobs: Sidekiq su service Fargate dedicato + SQS come coda.»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

Coerente con il requisito prestazionale:
> «In modo generale, l'applicazione dovrà risultare fluida e utilizzabile e tutte le operazioni "time consuming" dovranno essere delegate a sistemi batch.»
> — Capitolato C5, sezione «Requisiti di Prestazione»

**ADR correlato:** [0003 - SQS Instead Of Redis Queue](../architecture-decisions/0003-sqs-instead-of-redis-queue.md)

---

## 5. Cache e sessioni su Redis

**Scelta nella PoC:** cache e sessioni su Redis ([`config/cache.php`](../../config/cache.php):
`'default' => env('CACHE_STORE', 'redis')`; [`config/session.php`](../../config/session.php):
`'driver' => env('SESSION_DRIVER', 'redis')`). Redis è lo strumento giusto per dati a bassa
latenza e volatili (cache, sessioni, rate limiting); tenerlo distinto dal backend di coda
mantiene le responsabilità separate e prevedibili.

**Riscontro nel Capitolato:**
> «Cache/sessioni: ElastiCache for Redis.»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

In locale Redis è eseguito come servizio container, simulazione locale di ElastiCache for Redis.

**ADR correlato:** [0003 - SQS Instead Of Redis Queue](../architecture-decisions/0003-sqs-instead-of-redis-queue.md)

---

## 6. Database PostgreSQL

**Scelta nella PoC:** PostgreSQL come connessione predefinita
([`config/database.php`](../../config/database.php): `'default' => env('DB_CONNECTION', 'pgsql')`),
in locale come servizio container `postgres`. Un RDBMS robusto con vincoli di integrità,
transazioni, JSONB e Row-Level Security è la base adatta a stati applicativi, audit e dati
estratti che devono restare consistenti e interrogabili.

**Riscontro nel Capitolato:**
> «Database: Amazon RDS for PostgreSQL (Multi-AZ, snapshot automatici).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

La PoC ne è la simulazione locale: stesso motore PostgreSQL, senza Multi-AZ/snapshot gestiti di RDS.

**ADR correlato:** —

---

## 7. Object storage S3 (bucket uploads/processed) e cifratura KMS

**Scelta nella PoC:** disco predefinito S3 ([`config/filesystems.php`](../../config/filesystems.php):
`'default' => env('FILESYSTEM_DISK', 's3')`), bucket e chiave KMS provisionati via Terraform su
LocalStack ([`infra/localstack/main.tf`](../../infra/localstack/main.tf): `aws_s3_bucket.documents`,
`aws_kms_key.documents`, `aws_s3_bucket_server_side_encryption_configuration.documents`). Tenere
i binari fuori dal database, cifrarli a riposo e separare i bucket `uploads`/`processed` isola
input non fidato dai prodotti di lavorazione: una buona pratica di sicurezza prima ancora che
un requisito.

**Riscontro nel Capitolato:**
> «Storage documenti: S3 (bucket separati per "uploads" e "processed"), S3 Lifecycle per tiering/retention, S3 Object Lock opzionale (legal hold).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

> «Sicurezza dati: KMS per chiavi gestite (S3, RDS, Secrets).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

La PoC è la simulazione locale di S3+KMS tramite LocalStack; Lifecycle e Object Lock non sono attivati.

**ADR correlato:** [0004 - LocalStack And Terraform](../architecture-decisions/0004-localstack-terraform.md)

---

## 8. Gestione segreti (Secrets Manager + SSM Parameter Store)

**Scelta nella PoC:** caricamento runtime di parametri e segreti da SSM e Secrets Manager
([`app/Copilot/Support/RuntimeConfigurationLoader.php`](../../app/Copilot/Support/RuntimeConfigurationLoader.php):
`SsmClient`, `SecretsManagerClient`), provisionati via Terraform
([`infra/localstack/main.tf`](../../infra/localstack/main.tf): `aws_secretsmanager_secret.app_runtime`,
`aws_ssm_parameter.app_runtime`). Tenere i segreti fuori dal codice e dalle immagini, caricandoli
a runtime da un secret store, è il modo corretto per gestire credenziali e abilitare rotazione e
audit.

**Riscontro nel Capitolato:**
> «Segreti: Secrets Manager (credenziali DB, API keys, JWT secrets).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

La PoC è la simulazione locale di Secrets Manager/SSM tramite LocalStack.

**ADR correlato:** [0004 - LocalStack And Terraform](../architecture-decisions/0004-localstack-terraform.md)

---

## 9. Autenticazione/autorizzazione (identità simulata, JWT, RBAC/ABAC server-side)

**Scelta nella PoC:** nessun IdP reale; middleware di identità che inietta claim equivalenti
a OIDC/SAML (id, email, tenant, ruoli) — modalità locale o trusted-header
([`app/Http/Middleware/ResolvePocIdentity.php`](../../app/Http/Middleware/ResolvePocIdentity.php))
— e autorizzazione RBAC/ABAC server-side
([`app/Http/Middleware/AuthorizePocAccess.php`](../../app/Http/Middleware/AuthorizePocAccess.php)).
L'autorizzazione applicata lato server è l'unica fonte di verità affidabile sugli accessi;
simulare l'identità dietro un confine ben definito permette di sviluppare e testare l'authz
senza accoppiare la PoC a uno specifico IdP.

**Riscontro nel Capitolato:**
> «Identity/Access: Amazon Cognito (pool utenti/identity) oppure identity provider esterno; token JWT verso Rails.»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

Il modello dei ruoli adottato coincide con quello descritto:
> «Definizione ruoli (Admin CdL, Editor, Viewer, Auditor).»
> — Capitolato C5, sezione «Ambito Funzionale → AI Co-Pilot, UC-9: Gestione ruoli, permessi e policy»

La PoC simula in locale l'avvenuta autenticazione di un IdP corporate e implementa
l'autorizzazione lato Laravel; il token JWT verso il backend è modellato dal middleware di identità.

**ADR correlato:** [0007 - Authn Authz Boundary](../architecture-decisions/0007-authn-authz-boundary.md)

---

## 10. Osservabilità e audit trail

**Scelta nella PoC:** request/correlation ID, log JSON strutturati, metriche golden-signal in
formato Prometheus e gateway OpenTelemetry Collector locale → Prometheus/Tempo/Grafana
([`config/observability.php`](../../config/observability.php),
[`docker-compose.yml`](../../docker-compose.yml): `otel-collector`, `prometheus`, `tempo`, `grafana`),
più tabella append-only `audit_events`. Una pipeline asincrona è gestibile solo se è osservabile:
golden signals per l'esercizio e un audit immutabile per la tracciabilità delle azioni rilevanti.
OpenTelemetry mantiene il confine vendor-neutral verso qualsiasi backend futuro.

**Riscontro nel Capitolato:**
> «CloudWatch Logs/Metrics/Alarms, X-Ray (tracing).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Observability & Ops»

L'audit trail riflette il flusso di tracciabilità documentale richiesto:
> «Visualizzazione audit trail: upload → riconoscimento → split → mapping → invio → lettura.»
> — Capitolato C5, sezione «Ambito Funzionale → AI Co-Pilot, UC-8: Ricerca, audit e conservazione»

La PoC usa lo stack OTel/Prometheus/Tempo/Grafana (vendor-neutral, open source) come
equivalente locale di CloudWatch/X-Ray.

**ADR correlato:** [0006 - Observability And Audit](../architecture-decisions/0006-observability-and-audit.md)

---

## 11. Servizio AI di generazione contenuti (AI Assistant)

**Scelta nella PoC:** generazione di titolo/testo via Bedrock
([`config/services.php`](../../config/services.php): `'bedrock' => ['model_id' => ...]`),
con tono e stile parametrizzati. Il backend resta il punto di controllo attorno al modello:
valida lo schema della risposta, persiste il risultato come bozza e ne traccia generazione e
qualità. L'AI produce il contenuto, l'applicazione mantiene responsabilità su validazione,
stato e tracciabilità.

**Riscontro nel Capitolato:**
> «AI Assistant Generativo, dovrà permettere agli utenti della dashboard di creare in autonomia contenuti accattivanti con titolo, descrizione e immagine di copertina attraverso l'uso di AI generativa adeguando tono e stile della comunicazione a quello aziendale (formale, informale, ecc..)»
> — Capitolato C5, sezione «Requisiti di Business»

> «Il sistema invia la richiesta al motore AI.»
> — Capitolato C5, sezione «Ambito Funzionale → AI Assistant Generativo, UC-1: Creazione di un nuovo contenuto tramite prompt»

Nota: il Capitolato prescrive l'uso di un «motore AI» generativo ma non nomina uno specifico
servizio cloud; la scelta di Amazon Bedrock è interna alla PoC (vedi elenco finale).

**ADR correlato:** [0005 - No Automatic Fallbacks](../architecture-decisions/0005-no-automatic-fallbacks.md)

---

## 12. Servizio AI di OCR/riconoscimento documentale (Co-Pilot)

**Scelta nella PoC:** OCR/parsing e split documentale via Bedrock/Textract
([`config/services.php`](../../config/services.php): `'bedrock'`, `'textract'`), con soglia di
confidenza (`poc_confidence_threshold`). Affiancare OCR e classificazione a una soglia di
confidenza misurabile rende il riconoscimento verificabile e instradabile verso la revisione
umana quando l'affidabilità è bassa: la base per garantire qualità su documenti eterogenei.

**Riscontro nel Capitolato:**
> «AI Co-Pilot per Consulenti del Lavoro in grado di riconoscere la tipologia di documenti caricati (cedolini, comunicazioni, documenti da firmare, ecc..) e i destinatari, direttamente dal documento e consegnarli ai destinatari anche in modo massivo.»
> — Capitolato C5, sezione «Requisiti di Business»

> «Il sistema esegue OCR/Parsing e normalizza il testo.»
> — Capitolato C5, sezione «Ambito Funzionale → AI Co-Pilot, UC-2: Riconoscimento documento (classificazione + OCR)»

La soglia di confidenza adottata è allineata al criterio di accettazione:
> «AI Co-Pilot: confidenza media OCR ≥ 90%, mapping CF ≥ 99%.»
> — Capitolato C5, sezione «Criteri di Accettazione»

Nota: come per §11, lo specifico servizio (Bedrock/Textract) è scelta interna alla PoC; il
Capitolato prescrive la funzione OCR ma non il vendor.

**ADR correlato:** [0005 - No Automatic Fallbacks](../architecture-decisions/0005-no-automatic-fallbacks.md)

---

## 13. Human-in-the-Loop e soglia di confidenza

**Scelta nella PoC:** stati di revisione del sotto-documento (`needs_review`, `auto_validated`,
`quarantined`, `manually_validated`) e correzione manuale dei campi estratti sotto soglia di
confidenza. Sotto una certa affidabilità l'intervento umano è necessario per qualità e
compliance; modellarlo con stati espliciti rende il processo controllabile e auditabile invece
di accettare ciecamente l'output del modello.

**Riscontro nel Capitolato:**
> «2a. Bassa confidenza (< soglia) → richiede conferma/riclassificazione manuale.»
> — Capitolato C5, sezione «Ambito Funzionale → AI Co-Pilot, UC-2: Riconoscimento documento (classificazione + OCR)»

> «La UI mostra warning (tipo, destinatario, metadati) con cause/suggerimenti.»
> — Capitolato C5, sezione «Ambito Funzionale → AI Co-Pilot, UC-5: Revisione e correzione (Human-in-the-Loop)»

**ADR correlato:** —

---

## 14. Policy "nessun fallback automatico" dei modelli AI

**Scelta nella PoC:** in caso di fallimento di un servizio AI core, la pipeline passa a uno
stato `failed` esplicito e logga contesto non sensibile; nessuna sostituzione automatica con
dati surrogati ([`config/services.php`](../../config/services.php), pipeline Co-Pilot). Un
fallback silenzioso maschererebbe guasti di servizio o di permessi producendo dati sostitutivi
non tracciabili: in un flusso documentale è preferibile fallire in modo esplicito e osservabile,
così che l'errore sia evidente e gestibile anziché propagato a valle.

**Riscontro nel Capitolato:**
> «Caching locale e fallback open-source.»
> — Capitolato C5, sezione «Rischi e Mitigazioni» (mitigazione del rischio «Dipendenza da API esterne (LLM, OCR)»)

Il Capitolato cita il fallback open-source come *mitigazione* di un rischio, non come obbligo:
la PoC ne condivide l'obiettivo (non dipendere ciecamente dal servizio esterno) ma sceglie di
non automatizzarlo, perché la sostituzione silenziosa contrasterebbe con l'osservabilità e la
tracciabilità adottate; l'errore viene quindi reso esplicito anziché aggirato.

**ADR correlato:** [0005 - No Automatic Fallbacks](../architecture-decisions/0005-no-automatic-fallbacks.md)

---

## 15. Infrastructure-as-Code ed emulazione locale dei servizi cloud (LocalStack + Terraform)

**Scelta nella PoC:** Docker Compose per i processi locali e LocalStack per i servizi AWS-like,
provisionati con Terraform ([`infra/localstack/`](../../infra/localstack/): SQS+DLQ, S3, KMS,
SSM, Secrets Manager, EventBridge, Step Functions, SES). Descrivere l'infrastruttura come
codice rende l'ambiente riproducibile, revisionabile e veloce da ricreare; emularlo in locale
mantiene parità con AWS e abbatte i tempi di onboarding e di iterazione.

**Riscontro nel Capitolato:**
> «Eggon fornirà un ambiente di test e credenziali di sviluppo.»
> — Capitolato C5, sezione «Assunzioni del Progetto»

L'intera sezione «Componenti & servizi AWS» del Capitolato definisce i servizi target; la PoC
ne fornisce l'emulazione locale ripetibile (LocalStack), nello spirito di:
> «Integrazione complessa con NEXUM Core» → «API documentate e sandbox condivisa»
> — Capitolato C5, sezione «Rischi e Mitigazioni»

**ADR correlato:** [0004 - LocalStack And Terraform](../architecture-decisions/0004-localstack-terraform.md)

---

## 16. Hardening di rete e perimetro (Traefik/Nginx, IAM a minimo privilegio)

**Scelta nella PoC:** TLS edge via Traefik, Nginx come runtime statico SPA con superfici non
API bloccate, IAM role granulari sulle risorse LocalStack
([`docker-compose.yml`](../../docker-compose.yml): `traefik`, `nginx`;
[`infra/localstack/main.tf`](../../infra/localstack/main.tf): `aws_iam_role`). Cifrare il
traffico all'edge, ridurre la superficie esposta e applicare il minimo privilegio sono misure
di igiene di base che riducono il rischio a prescindere dall'ambiente.

**Riscontro nel Capitolato:**
> «Security Groups a "minimo privilegio".»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Rete & Sicurezza»

> «WAF + AWS Shield su ALB/CloudFront.»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Rete & Sicurezza»

La PoC modella in locale il principio di minimo privilegio e il perimetro edge; WAF/Shield e
ALB/CloudFront gestiti non sono replicati (equivalenti locali Traefik/Nginx).

**ADR correlato:** [0002 - Laravel API JSON](../architecture-decisions/0002-laravel-api-json.md)

---

## 17. Canale e-mail (identità SES provisionata)

**Scelta nella PoC:** identità mittente SES creata via Terraform
([`infra/localstack/main.tf`](../../infra/localstack/main.tf): `aws_ses_email_identity.sender`);
il codice di invio effettivo è fuori scope PoC. Predisporre l'identità mittente lascia il
canale e-mail pronto all'attivazione senza introdurre, in fase di prototipo, la complessità
dell'invio reale e della prova di consegna.

**Riscontro nel Capitolato:**
> «I canali di invio supportati includono NEXUM App e e-mail ordinaria.»
> — Capitolato C5, sezione «Vincoli»

> «Integrazioni e-mail/notify: SES (email), SNS (notifiche push/eventi).»
> — Capitolato C5, sezione «Vincoli tecnico tecnologici → Componenti & servizi AWS»

La PoC predispone l'identità SES in locale come simulazione del servizio; l'invio reale non è implementato.

**ADR correlato:** —

---

## Scelte interne non esplicitate nel Capitolato

Le seguenti scelte sono decisioni ingegneristiche interne alla PoC: rispondono a esigenze
concrete e non contrastano con il Capitolato, che però non le nomina esplicitamente. Rientrano
nella libertà tecnologica della sezione «Vincoli».

- **Vendor AI specifico (Amazon Bedrock per generazione e split, Amazon Textract per OCR):** il
  Capitolato prescrive un «motore AI» generativo e funzioni di «OCR/Parsing», ma non nomina
  alcun servizio cloud specifico per l'inferenza AI nella sezione «Componenti & servizi AWS».
  La scelta privilegia l'integrazione nativa con il resto dello stack AWS-like.
- **Orchestrazione con Step Functions:** la state machine
  ([`infra/localstack/main.tf`](../../infra/localstack/main.tf): `aws_sfn_state_machine.document_pipeline`)
  non è citata tra i «Componenti & servizi AWS»; rende espliciti stati, retry e gestione errori
  della pipeline, coerente con la delega delle operazioni «time consuming» a «sistemi batch»
  (sezione «Requisiti di Prestazione»).
- **Stack di osservabilità open source (OpenTelemetry Collector, Prometheus, Tempo, Grafana,
  Loki, Alertmanager):** adottato come equivalente locale, vendor-neutral, di CloudWatch/X-Ray,
  che il Capitolato indica come telemetria target.
- **Edge/runtime locale (Traefik, Nginx):** equivalenti locali di CloudFront/ALB, che il
  Capitolato nomina nella loro forma AWS gestita.
- **Librerie di manipolazione PDF (`setasign/fpdf`, `setasign/fpdi`) per lo split documentale:**
  dettaglio implementativo non coperto dal Capitolato.
