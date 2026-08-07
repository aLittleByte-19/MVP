# ADR 0010: Architettura esagonale (ports & adapters) per i domini Documents e Communications

Status: Proposed
Date: 2026-08-07

## Context

Il backend (Laravel 12 / PHP 8.4, API-only) è organizzato **per dominio applicativo**, non per
livello tecnico: `app/Mvp/{Ai,Ocr,Documents,Communications,Workflow,Identity,Audit,Observability,
Support}`. `docs/IMPLEMENTATION_OVERVIEW.md` descrive questa organizzazione come "buona
separazione controller→service" e la valuta "Solido per MVP" — questo ADR non nasce quindi per
correggere un problema già segnalato in quel documento, ma per rendere esplicita e verificabile
una proprietà che oggi esiste solo per convenzione, non per costruzione: **le regole di business
sono già mescolate con Eloquent, con l'SDK AWS e con HTTP dentro le stesse classi**, e questa
mescolanza non è vietata da nessun meccanismo, solo scoraggiata dall'uso.

Evidenza concreta della mescolanza, raccolta dal codice attuale:

- `App\Http\Controllers\Api\V1\DocumentController::index()` costruisce direttamente query Eloquent
  con `whereHas()` annidate per implementare quattro criteri di filtro (UC-35..UC-38), incluso un
  operatore di confidenza calcolato inline (`$operator = ... === 'above' ? '>=' : '<'`).
- `DocumentController::stream()` implementa un intero loop di polling SSE (`while (!
  connection_aborted())`, `sleep(1)`, diffing per evitare eventi duplicati) dentro il controller:
  ~100 righe di logica applicativa nel livello HTTP.
- `App\Http\Controllers\Api\V1\CommunicationController::favorite()`/`unfavorite()` implementano
  una regola di dominio ("preferito già impostato → 422") direttamente nel controller, non in un
  caso d'uso o in una policy.
- `CommunicationController::destroy()` risolve il disco di storage e chiama
  `Storage::disk($disk)->delete($coverPath)` direttamente nel controller.
- `App\Mvp\Documents\Services\DocumentProcessingService`, `App\Mvp\Communications\Services\
  CommunicationWorkflowService` e i due `WorkflowTaskHandler` (`DocumentWorkflowTaskHandler`,
  `CommunicationWorkflowTaskHandler`) combinano nella stessa classe: query/scritture Eloquent,
  chiamate dirette all'SDK AWS (`BedrockService`, `TextractService`, o `Aws\Sfn\SfnClient`
  iniettato senza wrapper), regole di business e side-effect infrastrutturali (audit, metriche,
  storage).
- `App\Mvp\Observability\PrometheusExporter::render()` calcola gauge interrogando direttamente
  `Communication`, `OriginalDocument`, `SubDocument` via Eloquent dentro una classe nominalmente
  "di osservabilità".
- Nessun binding in `App\Providers\AppServiceProvider::register()` lega un'interfaccia a
  un'implementazione: ogni servizio di dominio e ogni client AWS è bindato come singleton della
  **classe concreta**. Il codice applicativo digita (type-hint) sempre la classe concreta
  (`BedrockService`, `TextractService`, `SfnClient`), mai un'astrazione definita dal dominio.

Il progetto ha già, isolati e non generalizzati, tre elementi che vanno nella direzione giusta:

1. **`App\Mvp\Workflow\Contracts\WorkflowTaskHandler`**: un'interfaccia reale con due
   implementazioni di produzione (`DocumentWorkflowTaskHandler`, `CommunicationWorkflowTaskHandler`),
   selezionate a runtime da `WorkflowTaskRegistry::for(string $taskType)` e invocate da
   `WorkflowTaskRunner`, che resta agnostico rispetto al dominio specifico (dedup, claim, audit,
   metriche sono generici; solo `execute()` varia per implementazione). È l'unico punto della
   codebase dove esiste già una vera porta con adapter intercambiabili.
2. **`BedrockService`/`TextractService`**: isolano tutte le chiamate all'SDK AWS verso Bedrock e
   Textract, traducono le eccezioni AWS in eccezioni applicative con messaggio utente. Sono
   adapter nella sostanza, ma non nella forma: sono classi concrete, non implementazioni di
   un'interfaccia di dominio, e vengono digitate direttamente da chi le usa
   (`DocumentProcessingService`, `CommunicationWorkflowTaskHandler`, ecc.).
3. **Middleware HTTP** (`mvp.identity`, `mvp.authorize`, `throttle`): una Chain of Responsibility
   reale a livello di trasporto, ortogonale a questo refactor — resta invariata, agisce prima che
   la richiesta raggiunga l'adapter primario.

Il numero di integrazioni esterne da isolare è concreto e non ipotetico: due client Bedrock
(testo + immagine, regioni potenzialmente diverse), Textract, due macchine a stati Step Functions
(pipeline documenti e pipeline comunicazioni, entrambe con pattern callback/task-token), due coppie
di code SQS con DLQ, SSM Parameter Store e Secrets Manager per il bootstrap di configurazione.
`docs/architecture/final-architecture.md` dichiara come obiettivo che l'applicazione "parli con i
servizi AWS, reali o emulati, senza cambiare codice": oggi questo è vero solo a livello di
*endpoint/credenziali* (stessa classe concreta, configurazione diversa), non a livello di
*adapter sostituibile* — un cambio di provider LLM richiederebbe oggi di modificare `BedrockService`
e ogni sua chiamata, non di aggiungere un nuovo binding dietro un'interfaccia esistente.

A questo si aggiunge un vincolo specifico di questo contesto (progetto universitario, valutazione
in sede di colloquio orale): la struttura deve reggere a domande di approfondimento su *perché*
ogni confine esiste, non solo dimostrare che i nomi dei pattern compaiono nel codice.

## Decision

Adottare l'**architettura esagonale (ports & adapters)** in forma rigorosa, applicando la
**Dependency Rule**: le dipendenze del codice sorgente puntano sempre verso l'interno, dagli
adapter verso il dominio astratto — mai il contrario.

- Il **dominio** (core: entità, regole di business, porte) non importa `Illuminate\*`, Eloquent,
  l'SDK AWS o HTTP. È testabile con Pest puro, senza bootstrap del framework.
- Il dominio definisce due famiglie di porte:
  - **porte primarie** (inbound): contratti dei casi d'uso, invocati dall'esterno;
  - **porte secondarie** (outbound): contratti verso persistenza e servizi esterni.
- L'**applicazione** implementa le porte primarie orchestrando il dominio **esclusivamente**
  attraverso le porte secondarie — mai un accesso diretto a un model Eloquent, mai una chiamata
  diretta a un client AWS.
- Gli **adapter primari** (inbound) traducono un ingresso esterno in una chiamata a un caso d'uso
  tramite la sua porta primaria: **Controller HTTP**, **comandi Artisan**, e — punto specifico di
  questo progetto — i **`WorkflowTaskHandler`**, che sono adapter primari guidati da Step
  Functions/SQS invece che da HTTP. Generalizzare `WorkflowTaskHandler` a "adapter primario"
  invece che a "gestore di task" è la mossa concettuale centrale di questo ADR: non introduce un
  meccanismo nuovo, rinomina e generalizza uno già in produzione.
- Gli **adapter secondari** (outbound) implementano le porte verso persistenza (repository
  Eloquent) e verso servizi esterni (wrapper Bedrock, Textract, Step Functions, storage) —
  bindati porta→adapter nel service provider.

### Perimetro applicato

La struttura esagonale rigorosa si applica ai **due domini applicativi principali**: `Documents`
(Co-Pilot CdL) e `Communications` (AI Assistant). `Identity`, `Audit`, `Observability`, `Support`
e l'infrastruttura condivisa di `Workflow` **restano infrastruttura trasversale**, non
riorganizzata in porte/adapter proprie, per una ragione precisa e non per pigrizia: nessuna di
queste ha oggi (né si prevede abbia nell'MVP) un'implementazione alternativa da rendere
sostituibile — non esiste un secondo backend di audit, un secondo sistema di metriche, un secondo
loader di configurazione. Introdurre una porta per un collaboratore che non varierà mai è
esattamente il pattern-name-dropping decorativo che questo refactor deve evitare (vedi Vincoli
nel brief originale: "non creare porte per valori scalari o dettagli che non varieranno"). I casi
d'uso dei due domini principali continuano a dipendere direttamente da `AuditLogger` e
`MetricsRecorder` come servizi di infrastruttura condivisa, non come porte di dominio — è una
scelta di perimetro esplicita, motivata qui per non doverla rigiustificare in sede di colloquio.

`App\Mvp\Workflow\Contracts\WorkflowTaskHandler` resta dov'è (infrastruttura condivisa), ma cambia
ruolo concettuale: da "contratto di gestione task" a "porta primaria di dominio invocata da un
adapter guidato da Step Functions". Le sue due implementazioni (`DocumentWorkflowTaskHandler`,
`CommunicationWorkflowTaskHandler`) migrano dentro i rispettivi domini come adapter primari, e al
loro interno smettono di toccare Eloquent/SDK direttamente: delegano ai casi d'uso di dominio
tramite le stesse porte primarie usate dai Controller HTTP dello stesso dominio.

## Struttura di package proposta

```
app/Mvp/Documents/
├── Domain/
│   ├── Rules/                         # regole di dominio pure (es. ValidCodiceFiscale, già cosí)
│   └── Ports/
│       ├── Inbound/                   # porte primarie — un'interfaccia per caso d'uso
│       │   ├── SubmitDocumentUploadUseCase.php
│       │   ├── ListDocumentsUseCase.php
│       │   ├── ReviewDocumentUseCase.php
│       │   ├── ComposeSendMessageUseCase.php
│       │   ├── SplitDocumentUseCase.php           # invocata oggi da Bedrock via workflow
│       │   ├── ExtractDocumentFieldsUseCase.php    # invocata oggi da Bedrock via workflow
│       │   └── PersistOcrResultUseCase.php
│       └── Outbound/                  # porte secondarie
│           ├── DocumentRepository.php
│           ├── OcrGatewayPort.php                  # oggi implementata da TextractService
│           ├── DocumentAiGatewayPort.php           # oggi implementata da BedrockService (split/extract)
│           └── DocumentStoragePort.php
├── Application/
│   └── UseCases/                      # implementano le porte primarie, orchestrano via porte secondarie
│       ├── SubmitDocumentUploadService.php
│       ├── ListDocumentsService.php
│       ├── ReviewDocumentService.php
│       └── ...
└── Adapters/
    ├── Inbound/
    │   ├── Http/DocumentController.php             # adapter primario: HTTP → caso d'uso
    │   └── Workflow/DocumentWorkflowTaskHandler.php # adapter primario: Step Functions → caso d'uso
    └── Outbound/
        ├── Persistence/EloquentDocumentRepository.php
        ├── Ocr/TextractOcrAdapter.php               # implementa OcrGatewayPort
        ├── Ai/BedrockDocumentAiAdapter.php           # implementa DocumentAiGatewayPort
        └── Storage/FlysystemDocumentStorageAdapter.php

app/Mvp/Communications/
├── Domain/Ports/{Inbound,Outbound}/   # stessa forma: GenerateCommunicationUseCase,
│                                       # FavoriteCommunicationUseCase, CommunicationRepository,
│                                       # CommunicationAiGatewayPort, CommunicationPdfRendererPort,
│                                       # CommunicationCoverStoragePort, ...
├── Application/UseCases/
└── Adapters/
    ├── Inbound/{Http,Workflow}/
    └── Outbound/{Persistence,Ai,Pdf,Storage}/

app/Mvp/Workflow/                       # invariato: infrastruttura condivisa, non ridisegnata
├── Contracts/WorkflowTaskHandler.php   # ora descritto come porta primaria "guidata da workflow"
└── Services/{WorkflowTaskRegistry,WorkflowTaskRunner,WorkflowTaskHeartbeat}.php
    └── Ports/Outbound/WorkflowEnginePort.php  # NUOVA: astrae l'avvio di un'esecuzione Step
                                                 # Functions, oggi duplicato quasi identico in
                                                 # DocumentWorkflowService e
                                                 # CommunicationWorkflowService (entrambi
                                                 # iniettano SfnClient direttamente).
                                                 # Un solo adapter (SfnWorkflowEngineAdapter)
                                                 # implementa la porta per entrambi i domini.
```

Nota su `WorkflowEnginePort`: è l'unica porta condivisa fra i due domini. Non è
un'eccezione al perimetro deciso sopra — non riguarda Audit/Observability/Identity/Support, ma
elimina una duplicazione reale già presente (due classi che avvolgono `SfnClient` nello stesso
modo) spostandola in un unico punto, coerente con "Workflow" come infrastruttura condivisa alle
due pipeline (`docs/architecture/repository-structure.md` lo descrive già così).

Terminologia: sempre "dominio / applicazione / adapter primario / adapter secondario / porta
primaria / porta secondaria". Mai "livello", "layer", "tier".

## Verifica di conformità

La Dependency Rule deve essere verificabile automaticamente, non solo rispettata per disciplina:
un controllo statico (script CI, ad es. basato su `nikic/php-parser` o un tool tipo Deptrac) deve
fallire se un file sotto `app/Mvp/{Documents,Communications}/Domain/` importa un namespace
`Illuminate\*`, `Aws\*` o un model Eloquent. Questo è ciò che distingue questo refactor da un
riordino di cartelle: la regressione verso il mixing attuale diventa un errore di build, non un
richiamo in code review.

## Design pattern individuati (Compito 2bis)

Per ciascuno: dove si applica, quale problema risolve *in quel punto specifico*, cosa succederebbe
senza. Solo pattern con un adapter/porta/collaboratore reale dietro — nessuno è nominato per
completare l'elenco della specifica tecnica.

| Pattern | Categoria | Dove | Problema che risolve *lì* | Senza |
|---|---|---|---|---|
| **Adapter** | Strutturale | `EloquentDocumentRepository`, `BedrockDocumentAiAdapter`, `TextractOcrAdapter`, `SfnWorkflowEngineAdapter`, e gli equivalenti in Communications | Isola il dominio dalla forma specifica di Eloquent/SDK AWS: il caso d'uso parla con `DocumentRepository`, non con `SubDocument::query()`. È il pattern costitutivo dell'esagonale stesso. | Il dominio dipenderebbe da Eloquent/AWS direttamente (situazione attuale); nessun test di dominio senza bootstrap Laravel + credenziali AWS/LocalStack. |
| **Strategy** | Comportamentale | `WorkflowTaskHandler`, già esistente, generalizzato a porta primaria con adapter selezionato da `WorkflowTaskRegistry::for($taskType)` | `WorkflowTaskRunner` resta agnostico rispetto al dominio (dedup/claim/audit/metriche uguali per tutti); solo il passo di business varia. | `WorkflowTaskRunner` avrebbe bisogno di un `match`/`if` sul tipo di dominio, violando la Dependency Rule (l'infrastruttura di workflow dovrebbe conoscere i dettagli di Documents/Communications). |
| **Facade** | Strutturale | I servizi applicativi (`SubmitDocumentUploadService`, `GenerateCommunicationService`, ...) offrono all'adapter primario un solo metodo pubblico che coordina più porte secondarie | Il Controller/`WorkflowTaskHandler` non deve sapere quanti collaboratori servono per soddisfare un caso d'uso (repository + gateway AI + storage + regola di dominio). | Il Controller orchestrerebbe direttamente 4-5 servizi (è la situazione odierna in `DocumentController`/`CommunicationController`). |
| **Factory Method** | Creazionale | `WorkflowTaskRegistry::for(string $taskType): WorkflowTaskHandler` (già esistente, riletto in chiave esagonale come selettore dell'adapter primario corretto per tipo di task) | Centralizza la selezione dell'implementazione a runtime in un solo punto, senza che il chiamante (`WorkflowTaskRunner`) conosca le classi concrete. | Il runner dovrebbe istanziare/selezionare l'handler con logica propria, duplicata ad ogni punto di invocazione. |
| **Builder** | Creazionale | Assemblaggio di una `Communication` attraverso i tre passi asincroni di `CommunicationWorkflowTaskHandler` (`generate_text` → `generate_cover` → `finalize`): un `CommunicationBuilder` di dominio rende esplicito quali campi sono validi dopo ciascun passo | Oggi lo stato parziale valido ad ogni fase è implicito nei rami del `match` dentro l'handler. Un Builder esplicita l'invariante ("dopo `generate_text` titolo e corpo sono impostati, la copertina no") invece di lasciarlo dedotto dal codice. | Lo stato intermedio valido resta implicito e verificabile solo leggendo tutti i rami del `match`; un nuovo passo aggiunto male può produrre uno stato incoerente senza che nulla lo impedisca. |
| **Singleton** | Creazionale | Binding dei client AWS e degli adapter nel service provider — invariato nella sostanza, ma ora bindato **all'interfaccia di porta**, non alla classe concreta | I client AWS restano condivisi (costruzione costosa, connection reuse); il binding diventa il punto in cui si sceglie *quale* adapter soddisfa la porta. | Nessun punto unico di sostituzione: cambiare adapter richiederebbe cercare e modificare ogni type-hint concreto nella codebase (situazione attuale). |
| **Observer** | Comportamentale | Introdurre eventi di dominio (`DocumentoElaborato`, `ComunicazioneApprovata`, `BozzaScartata`) emessi dai casi d'uso, con listener separati per `AuditLogger` e `MetricsRecorder` | Oggi le chiamate ad audit/metriche sono sparse manualmente dentro `CommunicationWorkflowTaskHandler`, `DocumentWorkflowTaskHandler` e i controller: ogni nuovo "cosa deve succedere quando X accade" richiede toccare ogni punto che fa X. | Ogni nuova reazione a un evento (es. una notifica futura) richiede una modifica in ogni caso d'uso che genera quell'evento, invece di un nuovo listener. |
| **Command** | Comportamentale | Ogni caso d'uso applicativo è una classe con un solo metodo pubblico (`handle`/`__invoke`), coerente con la forma già usata da `WorkflowTaskHandler::execute()` | Un caso d'uso = una porta primaria = una responsabilità, testabile in isolamento passando mock delle porte secondarie. | I casi d'uso resterebbero metodi dentro service "fat" con più responsabilità (situazione attuale di `DocumentProcessingService`, `CommunicationWorkflowService`). |

Pattern valutati e **scartati esplicitamente** (nessuna finzione che "andrebbero comunque bene"):

- **Proxy** (caching/circuit-breaking davanti al gateway Bedrock/Textract): **scartato**. L'ADR
  0005 ("Nessun fallback automatico dei servizi AI") vieta esplicitamente di mascherare un
  fallimento di servizio AI con un comportamento sostitutivo — un circuit breaker che interrompe
  o devia le chiamate contraddirebbe direttamente quella decisione. Un Proxy qui sarebbe
  pattern-name-dropping in conflitto con un ADR già accettato, non un miglioramento architetturale.
  Riconsiderarlo richiederebbe prima riaprire esplicitamente l'ADR 0005, non è nello scope di
  questo refactor.
- **Abstract Factory**: **scartato**. Non esiste nel progetto una famiglia di adapter correlati
  che debba essere creata coerentemente insieme (es. "tutti gli adapter per il provider LocalStack"
  vs "tutti gli adapter per AWS reale" non sono in realtà famiglie diverse: sono la stessa classe
  con endpoint/credenziali diversi, per scelta esplicita di ADR 0004). Il Factory Method già
  presente (`WorkflowTaskRegistry`) copre l'unico bisogno di selezione reale.

## Consequences

- I due domini principali (Documents, Communications) diventano testabili senza bootstrap
  Laravel/DB/AWS per la parte di dominio e applicazione: i test dei casi d'uso passano mock delle
  interfacce di porta, non fake HTTP o LocalStack.
- Il numero di classi aumenta (un'interfaccia + un'implementazione per ogni porta, invece di un
  service unico): è un costo esplicito e accettato in cambio della sostituibilità e della
  testabilità, non un effetto collaterale.
- I Controller e i `WorkflowTaskHandler` si riducono a traduzione pura (richiesta → chiamata al
  caso d'uso → risposta): le regole oggi nei controller (favorite idempotente, filtri
  documento, transizione one-way di `send_status`) migrano nei casi d'uso.
- Il contratto OpenAPI pubblico e il comportamento osservabile (endpoint, payload, status HTTP)
  **non cambiano**: è un refactor architetturale interno, non una riscrittura funzionale (vincolo
  esplicito del brief).
- Introduce un costo di manutenzione per la verifica di conformità (script/tool di controllo
  dipendenze in CI) — accettato perché è ciò che rende la Dependency Rule reale invece che
  convenzionale.
- Identity/Audit/Observability/Support restano fuori scope: se in futuro uno di essi avrà bisogno
  reale di un'implementazione alternativa, andrà aperto un nuovo ADR che estende il perimetro con
  la stessa motivazione richiesta qui (non un'estensione automatica "già che ci siamo").

## Alternatives considered

- **Architettura a strati (layered/N-tier) esplicita** (Controller → Service → Repository →
  Model): è, di fatto, ciò che il progetto ha già informalmente ("service layer per dominio",
  come descritto in `IMPLEMENTATION_OVERVIEW.md` §5/§12) — e la mescolanza documentata sopra è
  la prova che formalizzarla non basta a impedirla. Un'architettura a strati non impone una
  direzione di dipendenza verificabile verso un nucleo astratto: uno "service" può legittimamente
  dipendere da Eloquent e dall'SDK AWS senza violare nessuna regola del layering, perché il
  layering vincola solo *chi può chiamare chi*, non *chi può conoscere cosa*. È esattamente il
  meccanismo mancante che ha permesso il mixing attuale. Scartata perché non risolve il problema
  che questo ADR affronta, lo rinomina.
- **Non formalizzare nulla, mantenere la struttura per dominio attuale**: scartata perché non
  offre alcun confine automaticamente verificabile e non risponde al requisito di dimostrare
  comprensione reale di ports & adapters in sede di colloquio — l'organizzazione per dominio è
  necessaria ma non sufficiente per l'esagonale.
- **Estendere l'esagonale a tutti i domini fin da subito** (inclusi Identity/Audit/Observability/
  Support): scartata per questo giro — nessuno di essi ha un bisogno di sostituibilità reale oggi;
  estenderli comunque sarebbe introdurre porte "per simmetria" invece che per necessità, la stessa
  cosa che il brief vieta esplicitamente per i pattern.

## Implementation evidence

Nessuna — questo ADR è `Proposed`: nessuna riga di codice del refactor è stata scritta. Diventerà
`Accepted, implemented` (parziale, poi completo) via via che il Compito 3 (refactor incrementale
dominio per dominio, con suite Pest verde ad ogni passaggio) procede, previa conferma esplicita
del richiedente.

## Related documents

- [`../architecture/repository-structure.md`](../architecture/repository-structure.md) — descrive
  già `Workflow` come infrastruttura condivisa alle due pipeline.
- [`../architecture/final-architecture.md`](../architecture/final-architecture.md) — obiettivo
  "stesso codice, AWS reale o emulata" che oggi si ottiene per configurazione, non per adapter.
- [`0003-sqs-instead-of-redis-queue.md`](0003-sqs-instead-of-redis-queue.md) — pattern
  callback/task-token che `WorkflowTaskHandler` implementa.
- [`0004-localstack-terraform.md`](0004-localstack-terraform.md) — motiva perché LocalStack/AWS
  reale sono la stessa classe concreta con endpoint diverso, rilevante per la scelta di scartare
  Abstract Factory.
- [`0005-no-automatic-fallbacks.md`](0005-no-automatic-fallbacks.md) — motiva lo scarto esplicito
  del pattern Proxy.
- [`0009-communication-async-pipeline-and-cover-storage.md`](0009-communication-async-pipeline-and-cover-storage.md) —
  pipeline che `CommunicationWorkflowTaskHandler` diventa adapter primario di.
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§3, §5, §12) — descrizione
  attuale del "service layer per dominio" che questo ADR formalizza e vincola.
