# ADR 0012: Sistema visivo e linguaggio dell'interfaccia della SPA

Status: Accepted, implemented
Date: 2026-08-18

## Context

La SPA Angular (`apps/frontend`) è funzionalmente completa ma il suo linguaggio visivo è cresciuto
per accumulo, non per decisione. Una ricognizione dell'intero frontend ha trovato difetti
misurabili, non questioni di gusto:

- **Nessuna definizione di `--mvp-danger-soft`.** `attention-note.css` la usava: per la specifica
  CSS la dichiarazione era *invalid at computed-value time* e, non essendo `background-color`
  ereditata, la nota di tono `alert` — l'unica usata, per la quarantena in Overview — rendeva senza
  sfondo.
- **L'anello di focus non raggiungeva il contrasto richiesto.** `--mvp-focus` valeva `#f28a52`
  (luminanza 0,377): contro le superfici del tema chiaro misurava 2,23–2,46, sotto il 3:1 di
  [SC 2.4.13](https://www.w3.org/WAI/WCAG22/Understanding/focus-appearance.html). Nel blocco scuro
  di `tokens.css` il token **non era ridefinito**, quindi il tema scuro usava un valore mai tarato
  per quel fondo. Lo stesso arancione era inoltre usato per altri quattro significati (bordo di
  alert, nota «watch», striscia e sparkline della scheda metrica).
- **Tipografia mai applicata.** Lo stack era `"Avenir Next", "Segoe UI", Arial`: Avenir Next esiste
  solo su macOS, quindi su Windows e Linux — dove il progetto gira — il carattere effettivo è
  sempre stato il secondo o il terzo.
- **Duplicazione strutturale.** La coppia etichetta + controllo era riscritta in otto varianti
  divergenti; `.warning`, `.errorNote`, `.previewLink`, `.eyebrow` esistevano in due o tre copie;
  cinque breakpoint non allineati (920, 900, 860, 760, 640); nomi di classe in due grafie
  (`.reviewActions` e `.review-actions` per lo stesso concetto).
- **Un dato mancante nell'elenco documenti.** La data e ora di caricamento, prescritta da UC-40.15,
  non compariva in tabella.
- **Un'azione senza vincolo.** Il comando che apre la preparazione del messaggio non aveva alcuna
  condizione — né `disabled` né `@if` — ed era quindi disponibile anche su un sotto-documento in
  quarantena, cioè uno la cui estrazione il sistema stesso dichiara inaffidabile.

Le scelte sono state prese costruendo cataloghi HTML/CSS/JS autonomi, fuori da Angular, con almeno
cinque alternative per sezione, ciascuna motivata da una fonte normativa o da un vincolo di
progetto. I cataloghi sono materiale di lavoro e non entrano nel repository: questo ADR ne è il
verbale.

**Vincoli al contorno**, tutti verificati e non negoziati qui:

- **RVC9-OB** impone axe e Pa11y «sulle interfacce utente principali», al plurale.
- **RVC10-OB** fissa browser evergreen (Chrome/Edge ≥ 147, Firefox ≥ 149, Safari ≥ 26.4): container
  query, `:has()`, `color-mix()` e `@layer` sono utilizzabili.
- La **CSP** (`docker/edge-cdn`, `docker/nginx`) vieta font e immagini da CDN esterna
  (`font-src 'self' data:`): un carattere proprio va ospitato in `apps/frontend/public/`.
- Il **budget di build** ferma un singolo foglio di stile a 16 kB.
- **ADR 0011** resta in vigore: la logica di presentazione vive nel ViewModel, i componenti
  condivisi e di feature non ne hanno uno.

## Decision

### Fondamenta

| Ambito | Decisione |
|---|---|
| Colore | La paletta esistente resta invariata. Si aggiunge la sola coppia mancante `--mvp-danger-soft` (`#fff0f0` / `#3b2222`) |
| Tipografia | **IBM Plex Sans** ospitato nel progetto. **IBM Plex Mono** solo dove il numero *è* il contenuto (il valore della scheda metrica), mai per cifre dentro testo corrente |
| Cifre in tabella | Restano in Plex Sans con `font-variant-numeric: tabular-nums`. L'incolonnamento lo dà `tnum`, non il cambio di famiglia |
| Separazione dei blocchi | Bordo + ombra, come oggi |
| Densità | **Politica per contesto**: compatta dove si scandisce (tabelle, elenchi), comoda dove si scrive (form, ispettore). **Container query** solo per i tre componenti che vivono in più contesti: scheda metrica, badge di stato, avanzamento a tappe |
| Focus | Anello semplice `3px solid`, colore **`#098faa`**, `outline-offset: 2px`. **Valore unico per entrambi i temi** |
| Etichette | Due ruoli distinti: **A** (sopratitolo, etichetta di indicatore) maiuscolo, peso 700, `letter-spacing: .07em`; **B** (etichetta di campo) minuscolo, peso 600 |

Il colore del focus deriva da una misura, non da una preferenza. Con `outline-offset ≥ 2px`
l'anello non tocca il riempimento del controllo: le adiacenze reali sono le sole superfici della
paletta. Esiste quindi una finestra di luminanza — **0,193–0,300** — in cui un colore unico supera
3:1 su tutte le superfici di entrambi i temi. `#098faa` misura 3,40–4,74 e la centra; l'arancione
precedente, a 0,377, ne era fuori. **`outline-offset ≥ 2px` diventa un vincolo di sistema**: a
offset zero la misura decade.

### Primitivi

- **Pulsante — gerarchia per collocazione.** Le azioni che chiudono il compito stanno in una barra
  a fondo pannello, sempre nello stesso punto; le accessorie restano in linea come collegamenti.
  Sostituisce la gerarchia per solo aspetto, che con sei azioni affiancate non distingueva nulla.
- **Campo — provenienza del dato.** Colore più **glifo dentro la casella, a destra**, staccato dal
  valore da un filo verticale: scintille per un campo estratto con buona confidenza, punto
  interrogativo per uno sotto la propria soglia, penna per un campo corretto a mano, lucchetto per
  un dato di sistema. Una casella vuota non porta glifo: non c'è provenienza da dichiarare. Legenda
  una volta sola, in cima al pannello. Il glifo è il secondo segnale *visivo* richiesto da
  [SC 1.4.1](https://www.w3.org/WAI/WCAG21/Understanding/use-of-color.html): il colore da solo non
  basta, e il testo per sole tecnologie assistive non sana la mancanza.
- **Badge — due forme per due grandezze.** Rettangolo a contorno per lo stato di **validazione**,
  pallino per lo stato di **scaricamento**, pieno o vuoto. Sono dati diversi e ora si distinguono
  anche per forma, non solo per posizione.
- **Scheda metrica — una forma per tipo di dato.** Non un solo componente col numero grande, ma
  otto: conteggio con barre giornaliere, quota, misura su scala con soglia, verdetto, ripartizione
  ad anello, tempo diviso per fase, densità come curva continua, media in stelle frazionarie. La
  forma la sceglie il tipo di dato che la scheda porta, e le schede stanno a mosaico su una griglia
  di quattro colonne: quelle con un asse o una legenda da leggere ne occupano due. Il tono di stato
  è il **bordo** della scheda, non una striscia laterale, che ripetuta su una fila di schede faceva
  una teoria di bandiere.
- **Vuoto, caricamento, errore.** Trattamenti invariati nella sostanza. Le segnalazioni a blocco
  (avviso di pagina, nota di attenzione, metriche non disponibili) portano il colore dello stato
  sul **riquadro intero**: erano tre disegni diversi per la stessa cosa, e comparivano una sopra
  l'altra.
- **Segnalazione.** Nell'intestazione della sezione che la riguarda, resa col rettangolo: è uno
  stato di validazione, quindi deve avere la forma degli altri.
- **Valutazione.** Stelle in **SVG** (non il carattere `★`, che ogni sistema disegna a modo suo),
  riempimento a cascata fino al valore scelto, bersaglio 44 px, nome accessibile per stella,
  affiancate dal valore in forma **`X/5`** — lo stesso pattern già usato dalla scheda metrica per
  il totale di riferimento.

### Flusso

La preparazione del messaggio è **disabilitata finché i dati non sono confermati**, con il motivo
scritto accanto al controllo. Un comando inattivo senza spiegazione è il difetto peggiore
dell'alternativa; nasconderlo toglie all'operatore la possibilità di sapere che quel passo esiste.

### Compositi

- **Storico documenti — sette colonne**, divise per natura del dato. Nelle colonne ciò che è
  successo al caricamento: tipologia, data e ora di caricamento, confidenza, validazione,
  scaricamento. Nella riga secondaria sotto il destinatario, il documento originale: azienda, nome
  file, data del documento. Validazione e scaricamento sono **colonne distinte**, quindi
  l'incolonnamento lo garantisce la tabella.
- **Ispettore — griglia piatta**, con la barra di azione a fondo pannello.
- **Avanzamento — tappe con tempo trascorso**, che risponde alla sola domanda reale davanti a una
  pipeline lenta ed evita di dover trattare `still_running` come messaggio a sé.

### Pagine

- **Guscio invariato**: sidebar larga con sotto-voci. Ne consegue che l'etichetta dei gruppi di
  navigazione **non può essere un heading** — la sidebar precede l'`<h1>` nel DOM e si creerebbe un
  salto di livello — e resta legata con `aria-labelledby`.
- **Overview**: struttura attuale **meno la sezione «Da dove partire»**, che non porta dati e
  ripete le voci della navigazione. I dati prendono la forma a riquadri, senza contenitore attorno.
  **Quali** dati mostrare resta aperto (vedi sotto).

### Linguaggio

| Ambito | Decisione |
|---|---|
| Registro | **Neutro operativo**: terza persona, niente «tu» né «noi». È quello già maggioritario ed è coerente con le stringhe prescritte dai requisiti, che sono tutte neutre |
| Azione bloccata | Si dice **cosa fare per sbloccare**, e quell'azione è il pulsante accanto: «Conferma i dati per preparare il messaggio.» |
| Attesa | **Etichetta invariata più indicatore**: nessun salto di larghezza, nessuna stringa aggiuntiva. Necessario perché «disabilitato» ora significa *vincolo*: usarlo anche per «sto lavorando» sovrapporrebbe due segnali |
| Errore | **Per famiglia**: rete, validazione, permesso, dato mancante — ciascuna con la propria azione. `getApiErrorMessage` distingue già lo status 0 dagli altri |
| Stati vuoti | Invariati, conservando la distinzione già presente fra «non c'è ancora nulla» e «nessun risultato per questi filtri» |

**Stringhe non modificabili**, perché citate alla lettera nell'Analisi dei Requisiti o perché
valori di enum del contratto: `Storico documenti analizzati` (UC-35..UC-39), `Non disponibile`
(UC-64..UC-70), `Genera bozza` (UC-1), `Invia` (UC-51), `Scaricato`/`Non scaricato` (UC-37,
UC-40.11), `Senza nome (N)` (UC-19/UC-20), toni e stili della comunicazione, tipologie di
documento, e le etichette che arrivano dal backend (`reviewStatusLabel`, `sendStatusLabel`).

### Convenzione dei nomi CSS

camelCase per le classi di componente (`.reviewActions`), coerente con la forma maggioritaria nel
codice. Le Norme di Progetto non normano il CSS: la convenzione la fissa questo ADR.

## Consequences

- L'anello di focus diventa conforme in entrambi i temi **e** l'override in tema scuro diventa
  superfluo, chiudendo il difetto del token non ridefinito.
- Il carattere va ospitato in `apps/frontend/public/` e pesa sul budget di build: va scelto un solo
  peso variabile o un sottoinsieme statico minimo.
- Le cifre in tabella dipendono dal supporto di `tnum` in Plex Sans: se il font non lo esponesse, le
  colonne numeriche non si incolonnerebbero e la cosa si vede a occhio nudo.
- La provenienza dei campi richiedeva che **il contratto esponesse il dato per campo**, e il
  backend non lo pubblicava: finché è stato così, tredici caselle portavano tutte il segno dello
  stato del documento. L'ADR 0013 lo ha chiuso — `fieldConfidences` e `lowConfidenceFields` sono nel
  contratto, e il glifo è ora quello del singolo campo. Resta fuori la provenienza *manuale*
  persistita (`manuallyDeclaredKeyFields`): la penna segna i campi corretti nella sessione in corso
  e, dopo il salvataggio, l'intera scheda validata a mano.
- Estendere il gate a11y a tutte e tre le pagine può far emergere violazioni oggi invisibili: è
  l'adempimento di RVC9-OB, non un'aggiunta facoltativa.
- La Specifica Tecnica §6.6 si allontana ulteriormente dal codice. Era già disallineata (elenca
  `LoadingStateComponent` e `ProgressBarComponent` come attivi, e descrive `MetricsPanel` che usa
  il primo); il verbale interno del 04/08/2026 dichiara il documento «in standby». Questo ADR è il
  testo da cui aggiornarla.

## Alternatives considered

- **Cambiare paletta** (superfici neutre, contrasto alto, carta e inchiostro, scuro nativo,
  monocromatico con il colore riservato alla confidenza): scartate. L'identità attuale è
  riconoscibile e i difetti misurati non nascevano dalla paletta ma da un token mancante e da un
  focus fuori scala.
- **Focus a doppio anello o a inversione**: non più necessari una volta stabilito che l'anello non
  tocca il riempimento del controllo e che esiste una finestra di luminanza utilizzabile. Restano
  la soluzione corretta se in futuro la paletta introducesse superfici a luminanza intermedia.
- **Confidenza per campo accanto a ogni etichetta**: non realizzabile. `ExtractSubDocumentFieldsService`
  calcola **un solo** `confidenceScore` per sotto-documento, come leggibilità OCR ponderata sulla
  completezza di quattro campi chiave; il contratto lo espone su `SubDocument`, non sui campi.
- **Modifica in linea nella tabella, coda di triage per confidenza, pannello laterale non modale,
  collegamento sorgente↔campo con bounding box**: non scartate, **rinviate**. Riguardano il modello
  di interazione e non il sistema visivo; il collegamento sorgente↔campo richiederebbe inoltre che
  la pipeline persista le coordinate restituite da Textract, che oggi non conserva.
- **Valutazione a pollice su/giù**: scartata. Lo schema OpenAPI vincola `rating` a un intero fra 1
  e 5 e la metrica «media stelle» è calcolata su quello.

## Questioni aperte

Non decise qui perché toccano i requisiti, non l'interfaccia. Vanno portate a chi tiene l'Analisi
dei Requisiti:

1. **Il pulsante «Invia» dovrebbe chiamarsi «Scarica»** — non avviene alcun invio nella MVP, ma
   UC-51 nomina il tasto alla lettera. Rinominare lo *stato* seguiva i requisiti (fatto,
   «Scaricato»/«Non scaricato» da UC-37 e UC-40.11); rinominare il *pulsante* li contraddice.
   *Verificato il 21/08/2026 sui rami della Documentazione*: la revisione del 13/08 ha portato il
   solo stato a «scaricamento» (UC-36, RF50-OB, RF64-OP), mentre UC-51 continua a dire «clicca il
   tasto "Invia"» e il Manuale Utente del 19/08 lo documenta con quel nome. Il pulsante resta
   «Invia» finché non lo cambiano i requisiti.
2. **La valutazione ripetibile.** UC-25 prescrive che il modulo si disabiliti «per evitare
   valutazioni multiple», e il vincolo è imposto in tre punti: l'entità `Communication::rate()`,
   il contratto, la View. Renderla modificabile è fattibile ma cambia il caso d'uso.
3. **Quale stato di revisione sblocca lo scaricamento**: se `auto_validated` basti — il documento
   che il sistema ha già ritenuto affidabile — o se serva comunque la conferma umana.
   *Deciso il 22/08/2026: serve la conferma umana.* Il comando resta spento su un documento
   validato in automatico, perché la soglia dice che il testo era leggibile, non che il documento
   sia della persona a cui verrà consegnato. Il vincolo vive in **due punti**, e non è una
   duplicazione: la View (`SubDocumentListComponent::canPrepareMessage`) tiene spento il comando e
   ne spiega il perché, il caso d'uso (`SendMessageService::export()`) lo impone, perché l'API si
   può chiamare senza passare dal pannello. L'anteprima resta libera: guardare il messaggio è il
   modo di decidere se confermare.
4. **Quali dati mostrare nella Overview.** Il criterio adottato in precedenza è scritto nel
   ViewModel: «le tre metriche su cui si agisce, non un riassunto di tutte», per non ripetere i
   conteggi che vivono nelle pagine dei moduli. Va riesaminato sapendo qual era il criterio.

## Implementation evidence

Già applicato (branch `refactor/ui_ux`, precede l'approvazione di questo ADR):

- `apps/frontend/src/styles/tokens.css` — coppia `--mvp-danger-soft` definita nei due temi.
- `apps/frontend/src/app/shared/components/status-badge/status-badge.css` — il tono `danger` usa il
  token corretto invece di ripiegare su `--mvp-accent-soft`.
- `apps/frontend/src/app/shared/components/metric-card/` — totale di riferimento reso come `X/Y`
  sulla linea di base del valore, con `su` per le tecnologie assistive.
- `apps/frontend/src/app/app.ts` — voci di navigazione come collegamenti con indirizzo reale;
  gruppi legati da `aria-labelledby`.
- `apps/frontend/src/app/features/assistant/components/communication-history-list.*`,
  `prompt-configuration-list.*` — storico estratto dalla pagina.
- `Makefile`, `.github/workflows/ci.yml` — gate a11y esteso a `/overview`, `/assistant`, `/copilot`.
- `app/Mvp/Documents/Domain/Enums/SendStatus.php`, `openapi/v1/alittlebyte-mvp-api.yaml` —
  terminologia dello stato allineata a UC-37 e UC-40.11.
- `apps/frontend/src/app/shared/styles/` — `field.css`, `notice.css`, `link-button.css`, `page.css`:
  il campo era riscritto otto volte, `.warning` quattro, `.errorNote` e i collegamenti-azione due.
  Copilot e Assistant non importano più `overview-page.css`.
- `apps/frontend/src/styles/tokens.css` — bordi di stato (`--mvp-*-border`, `--mvp-primary-soft`)
  al posto di `#f0bf98`, `#c9e5d3`, `#d99a3f`, `#6bb58a`; via i tre token senza consumatori.
- Cinque soglie responsive ridotte alle tre dichiarate (640, 900, 1100).
- `apps/frontend/src/app/shared/components/metric-*/`, `shared/util/charts.ts` — le otto forme
  della scheda metrica e la geometria dei loro grafici, tenuta fuori dai componenti perché è
  aritmetica e si prova senza montare nulla.
- `apps/frontend/src/app/features/copilot/components/field-origin/` — il glifo di provenienza del
  singolo campo, con le soglie per campo dell'[ADR 0013](0013-per-field-ocr-confidence.md).
- `apps/frontend/src/app/shared/styles/field.css` — fuori dalla modifica il cursore non compare
  nelle caselle in sola lettura: `readonly` le lascia attivabili col clic, e il cursore prometteva
  una scrittura che il campo non accetta.
- `apps/frontend/src/app/features/copilot/components/document-list.*` — storico a sette colonne, con
  il documento di partenza nella prima cella; le larghezze stanno nel foglio di chi le usa.
- `apps/frontend/src/app/shared/components/status-dot/` — pallino per lo stato di scaricamento,
  distinto dal rettangolo della validazione.
- `apps/frontend/src/app/shared/components/star-rating/` — gruppo di radio con stelle vettoriali,
  bersagli da 44px, nome per ogni valore e punteggio come `X/5`. Spostato sotto `shared/components/`.
- `apps/frontend/src/app/features/copilot/components/sub-document-list.*` — contrassegno per campo e
  legenda che dicono l'origine del dato senza dipendere dal colore.
- `apps/frontend/src/app/shared/components/stage-progress/` — tempo trascorso accanto alle tappe.
- `apps/frontend/src/app/shared/components/button/` — `busy`: l'etichetta del comando resta ferma e
  il lavoro in corso passa da un indicatore e da `aria-busy`.

Scostamenti rispetto a quanto deciso in catalogo, e perché:

- **Provenienza per campo.** Il contratto espone un solo `reviewStatus` per sotto-documento e un
  solo punteggio di confidenza: non dice quali campi siano stati dichiarati a mano. Il contrassegno
  riflette quindi lo stato del sotto-documento, non del singolo campo. Colmarlo richiede un campo
  nuovo nel contratto.
- **Valutazione ripetibile.** Non implementata: resta la questione aperta 2. La View ora dichiara il
  vincolo prima del clic invece di comunicarlo dopo.
- **`<form>` nei pannelli che non si inviano.** Filtri, metadati di caricamento, ispettore e
  messaggio precompilato erano `<form>` senza comando di invio: Pa11y lo segnalava su tre pagine
  (H32.2). Sono diventati gruppi di controlli (`role="search"`, `role="group"`) e il salvataggio è
  un comando esplicito.

Esito del gate di accessibilità sulle tre pagine, con lo stack locale in piedi: **axe 0 violazioni,
Pa11y 0 problemi**. Il solo `csp-smoke` fallisce su `/copilot` per una condizione d'ambiente e non
di codice: con `MVP_DOCUMENT_DISK=real_s3` l'anteprima cerca il file sul bucket AWS reale, che in
locale non lo ha, e l'endpoint risponde 503.

## Related documents

- [ADR 0011](0011-frontend-presentation-model-and-sse-client.md) — ViewModel puro: resta in vigore,
  questo ADR non tocca il confine View/ViewModel.
- [ADR 0008](0008-angular-frontend-static-serving.md) — SPA statica da S3/CDN, da cui discende il
  vincolo CSP sui font.
- `docs/mvp-scope.md` — perimetro funzionale, incluso lo stato di scaricamento.
- Specifica Tecnica §6.6 (repo `Documentazione`, branch `specifica_tecnica`) — da aggiornare a
  partire da questo ADR.
