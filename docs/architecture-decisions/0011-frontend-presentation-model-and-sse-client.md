# ADR 0011: ViewModel puro (Presentation Model) e client SSE su `fetch`

Status: Accepted, implemented
Date: 2026-08-16

## Context

Il frontend Angular (`apps/frontend`) usa MVVM solo nel senso lasco insegnato a lezione: il
`@Component` stesso è il ViewModel, accoppiato ad Angular (`inject()` a livello di campo,
`effect()`, `DestroyRef`) e non istanziabile con un `new` in un test puro — richiede sempre
`TestBed`/`Injector.create()`. Verificato concretamente prima di questo ADR: in tutta la suite di
test del frontend, zero classi con dipendenze Angular venivano istanziate con `new` diretto.

Separatamente, l'avanzamento delle pipeline asincrone (upload documenti, generazione
comunicazioni) veniva seguito lato SPA con l'`EventSource` nativo del browser, che ha due limiti
strutturali: non può inviare header HTTP custom, e collassa l'evento nominato `error` inviato dal
backend con la caduta della connessione TCP nello stesso listener — un blip di rete e un
fallimento reale della pipeline diventavano indistinguibili lato client.

Questo ADR documenta retroattivamente due decisioni già implementate (non nuove al momento della
scrittura): il refactor a ViewModel puri per le tre pagine (`Copilot`, `Assistant`, `Overview`) e
la sostituzione di `EventSource` con un client SSE su `fetch`. Nessun ADR le copriva finora.

## Decision

### ViewModel puro (Presentation Model, Fowler)

Ogni pagina (`CopilotPage`, `AssistantPage`, `OverviewPage`) ha un `*ViewModel` sibling
(`copilot-page.view-model.ts`, ecc.): una classe TypeScript pura, costruita con `new`, che riceve
le sue dipendenze dal costruttore invece che da `inject()`. Il Component (View) resta l'unico
punto accoppiato ad Angular: si procura le dipendenze con `inject()`, costruisce l'istanza del
ViewModel, e il template legge solo `vm.*` — mai lo store condiviso direttamente (`error`,
`loading`, `metrics` sono `computed()` pass-through esposti dal ViewModel).

`signal()`/`computed()` restano nel ViewModel: sono primitive reattive di piattaforma (analogo a
`INotifyPropertyChanged` in WPF), non infrastruttura di rendering — non richiedono un injection
context, quindi non compromettono la testabilità con `new`. La distinzione che conta è questa, non
"zero import da `@angular/core`".

**Cosa resta nella View, per un limite strutturale non aggirabile**: `effect()` e
`takeUntilDestroyed()` richiedono un injection context che una classe costruita con `new` non ha.
L'`effect()` nel costruttore del Component resta minimo — legge i segnali sorgente (per il
dependency-tracking) e chiama un metodo del ViewModel (`vm.reload()`), che possiede la vera
chiamata di ricerca, esattamente come ogni altra azione del ViewModel (`upload()`, `generate()`,
...). Il form reattivo (`filterForm`, binding `formControlName`) resta anch'esso nella View: è
infrastruttura di binding col template, non logica da testare in isolamento.

**Operazioni DOM**: nessun ViewModel chiama `document`/`window` direttamente. Lo scroll
(`scrollToElement`, `shared/util/scroll.ts`) è una funzione pura iniettata dal costruttore, non un
metodo del ViewModel — la View la costruisce e la passa, così il ViewModel può *chiedere* uno
scroll senza *eseguirlo*.

**Teardown**: `reload()` annulla la propria sottoscrizione precedente prima di avviarne una nuova
(una risposta di ricerca tardiva non deve sovrascrivere un risultato più recente). Un metodo
`destroy()`, chiamato dalla View via `DestroyRef.onDestroy()`, annulla la stessa sottoscrizione
alla distruzione del componente. Le azioni di scrittura (`upload()`, `generate()`, `discard()`,
...) non vengono annullate: devono completare lato server anche se l'utente ha già navigato
altrove, così l'operazione risulta comunque effettuata al ritorno sulla pagina — cancellarle
sarebbe un cambio di comportamento silenzioso, non solo una pulizia di risorse.

**Cosa il ViewModel non è**: non è indipendente da Angular in senso stretto (usa `signal`), e
`Router`/`MvpStateStore`/`DocumentWorkflowService`/`AssistantService` restano tipi Angular
concreti iniettati dal costruttore — non invertiti dietro funzioni come lo scroll. La ragione:
sono già dipendenze esplicite e mockabili in un test puro con un oggetto fittizio castato
(`{ navigate } as unknown as Router`), esattamente come già avviene negli spec esistenti — non
c'è una dipendenza globale nascosta da eliminare, a differenza di `document`/`window`.

### Client SSE su `fetch` (`SseClient`)

`core/http/sse-client.ts` sostituisce `EventSource` per tutte le connessioni SSE della SPA.
Motivato da due limiti concreti di `EventSource`, non da preferenza stilistica:

1. **Header di correlazione**: `EventSource` non supporta header custom. Con l'identità
   `trusted_headers` (vedi ADR 0007), la richiesta SSE non porta `X-Mvp-*` e il middleware la
   rifiuta — un percorso "production-like" incompatibile con l'unico meccanismo di progress della
   SPA. `SseClient` usa `fetch()` con header espliciti (`REQUEST_ID_HEADER`,
   `CORRELATION_ID_HEADER`), quindi lo stream partecipa alla stessa identità/correlazione di ogni
   altra richiesta.
2. **`error` nominato vs caduta di connessione**: `EventSource` collassa entrambi sullo stesso
   listener `onerror`. `SseClient` li distingue (`onNamedError` per l'evento SSE `error` inviato
   dal backend, `onConnectionError` per un `fetch()` fallito/abortito) — un blip di rete non viene
   più mostrato come fallimento definitivo della pipeline.

`consumeSseBuffer()` fa il parsing dei frame SSE a mano (separatore riga vuota, prefissi
`event:`/`data:`) perché `fetch()` con `ReadableStream` non ha un parser SSE nativo come
`EventSource`. `dispatchSseFrame()` avvolge `JSON.parse` in try/catch: un payload malformato
diventa `{ message: rawData }` invece di rompere silenziosamente l'handler.

`DocumentWorkflowService`/`AssistantService` iniettano `SseClient` (non i Component/ViewModel
direttamente): la connessione SSE resta un dettaglio del layer dati, coerente con l'upload/la
generazione che sono già Observable lì.

## Consequences

- Le tre pagine hanno un ViewModel puro testabile con `new`, senza `TestBed`/`Injector.create()` —
  la suite `*.view-model.spec.ts` lo dimostra direttamente, non solo a parole.
- Il costo esplicito: due file per pagina invece di uno (Component + ViewModel), stesso tipo di
  trade-off già accettato lato backend per porte/adapter (vedi ADR 0010).
- `SseClient` aggiunge un parser SSE manuale (`consumeSseBuffer`) che `EventSource` offriva
  gratis — un costo di manutenzione esplicito in cambio di header custom e della distinzione
  errore-nominato/caduta-connessione, entrambi non ottenibili altrimenti.
- La fase `still_running` (vedi ADR 0010, timeout SSE allineato ai timeout ASL) è un valore proprio
  di `DocumentUploadPhase`, distinto sia da `completed` che da `failed`, propagato fino alla
  progress bar (`upload-progress.ts`): un timeout dello stream non appare mai come errore.

## Alternatives considered

- **Polling invece di SSE**: scartato, non ridiscusso qui — la scelta SSE è precedente a questo
  ADR e non rimessa in discussione.
- **`EventSource` con un secondo canale per gli header** (es. query string invece di header):
  scartato — porterebbe id di correlazione in URL loggati (proxy, browser history), lo stesso
  errore che le altre richieste API evitano passandoli come header.
- **Component come ViewModel (stato preesistente)**: è ciò che il corso richiede come minimo (le
  slide del prof definiscono il `@Component` stesso come ViewModel) — scartato come *unica*
  implementazione perché non regge a un test costruito con `new`, il requisito esplicito di questo
  refactor.

## Implementation evidence

- `apps/frontend/src/app/features/{copilot,assistant,overview}/*.view-model.ts` — ViewModel puri,
  costruttore con dipendenze esplicite, nessun `inject()`.
- `apps/frontend/src/app/features/{copilot,assistant,overview}/*.view-model.spec.ts` — istanziati
  con `new`, zero `TestBed`/`Injector.create()`.
- `apps/frontend/src/app/shared/util/scroll.ts` — unica funzione che tocca `document`/`window`,
  iniettata dal costruttore del ViewModel.
- `apps/frontend/src/app/core/http/sse-client.ts` (+ `sse-client.spec.ts`) — client SSE su `fetch`.
- `apps/frontend/src/app/features/copilot/data/document-workflow.service.ts`,
  `apps/frontend/src/app/features/assistant/data/assistant.service.ts` — iniettano `SseClient`,
  mappano `still_running`/`onNamedError`/`onConnectionError` sulle fasi esposte alla UI.
- `apps/frontend/src/app/shared/components/error-state/error-state.ts` — pulsante "Riprova"
  (`canRetry`/`retry`), usato per il recupero manuale dal primo `GET /state` fallito.

## Related documents

- [ADR 0010](0010-hexagonal-architecture-documents-communications.md) — architettura esagonale
  backend; la sezione "Giro di hardening da revisione esterna" copre il timeout SSE allineato ai
  timeout ASL e il ridimensionamento del pool PHP-FPM che questo ADR presuppone lato client.
- [ADR 0007](0007-authn-authz-boundary.md) — identità `trusted_headers`, il vincolo che rende
  necessari gli header custom sullo stream SSE.
