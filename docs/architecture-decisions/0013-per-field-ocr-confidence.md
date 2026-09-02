# ADR 0013: Confidenza per campo invece che media di pagina

Status: Accepted, implemented
Date: 2026-08-20

## Context

La confidenza di un sotto-documento decide se questo si valida da solo o finisce in revisione
umana. Fino a questa decisione il punteggio era:

```
confidence_score = round(confidenzaOcrMediaDellePagine × completezzaDeiCampiChiave)
```

dove la completezza è la frazione dei quattro campi chiave — nome, cognome, azienda, data —
effettivamente restituiti dal modello, al netto di quelli dichiarati a mano in fase di caricamento.

La formula è documentata in `docs/mvp-scope.md` ed è una scelta consapevole su un punto
importante: **non si usa l'autovalutazione del modello**, che sarebbe circolare. Quella parte resta
valida e non è in discussione qui.

Il difetto sta nell'altro fattore, la leggibilità.

### La media di pagina misura il contorno, non il dato

`TextractOcrAdapter` riceve da Textract la confidenza di **ogni riga** (`LINE`), la usa per
calcolare una media per pagina, e poi **scarta il dettaglio**: in `ocr_pages` restava solo
`confidenceAvg`. Da lì in avanti nessuno poteva più sapere quanto fosse leggibile *il cognome del
destinatario*; si sapeva solo quanto fosse leggibile *la pagina*.

Ma una pagina di cedolino è quasi tutta contorno: intestazioni di tabella, etichette, note legali,
voci retributive. Textract le legge intorno al 99%. I campi che identificano il destinatario sono
quattro righe. Mediando, il contorno domina.

La conseguenza è concreta e verificabile: **una pagina in cui il cognome è illeggibile al 41% e
tutto il resto è nitido ha una media intorno al 90%, supera la soglia di 80 e si valida da sola**,
con il destinatario sbagliato. In un sistema che consegna documenti HR alla persona giusta, è il
modo peggiore di sbagliare, perché sbaglia in silenzio.

La stessa media era anche l'unico ingrediente disponibile: moltiplicandola per una frazione a
quarti, i punteggi possibili erano pochi e distanti, e sopra la soglia di 80 ci arrivava solo la
completezza piena. Il punteggio esposto da UC-40.10 aveva quindi poca capacità di discriminare.

### Misure raccolte

Su un documento di prova a tre destinatari passato per la pipeline reale (Textract su
`eu-central-1`, Bedrock `amazon.nova-lite-v1:0`):

| Documento | Confidenza media OCR | Esito con la formula precedente |
|---|---|---|
| impaginato digitalmente | 98,05 | validato in automatico |
| stessa pagina, scansione degradata | 94,59 | validato in automatico |
| stessa pagina, scansione molto degradata | 52,20 | in revisione, punteggi 41 / 49 / 40 |

Nel terzo caso gli errori di lettura sono arrivati fino ai nomi — «Bertolini» per Bertolino,
«Massuleni» per Mazzoleni — e il documento è stato correttamente instradato alla revisione. Ma vi
è arrivato solo perché era degradata *tutta* la pagina: un degrado locale sul solo nome, che è il
caso reale di un timbro o di una piega, avrebbe lasciato la media alta.

### Cosa vincolano i requisiti

I documenti di progetto vincolano l'**interfaccia** della confidenza, non il suo calcolo:

- Glossario: «Percentuale che indica l'affidabilità del riconoscimento automatico»;
  «Soglia di Confidenza: valore limite per l'intervento manuale (es. 80%)».
- UC-40.10 / RF64-OB: la confidenza si mostra in percentuale da 0 a 100.
- UC-56.3 / RF91-OB: si espone quanti documenti stanno sotto l'80%.
- UC-38 / RF52-OB: lo storico si filtra sopra o sotto una soglia.
- Capitolato, Criteri di Accettazione: «AI Co-Pilot: confidenza media OCR ≥ 90%,
  **mapping CF ≥ 99%**».

Nessuna fonte prescrive la formula. Il criterio di accettazione dice però una cosa che
l'implementazione precedente appiattiva: il committente **guarda al codice fiscale separatamente
dal resto**, perché è il dato che identifica la persona.

Attenzione a non leggerlo per più di quel che dice: «mapping CF ≥ 99%» è un obiettivo di
**accuratezza misurato sulla popolazione** — il 99% dei codici fiscali dev'essere mappato
correttamente — non una soglia di confidenza OCR da applicare a ogni singolo documento. Le due
cose si somigliano e non lo sono: la prima si verifica contando gli errori su un campione, la
seconda instrada un documento alla revisione. Confonderle porta a una soglia che nessun documento
può superare (si veda la nota sulla taratura, più avanti).

### Riferimenti esterni

Il modello canonico di AWS per l'inoltro alla revisione umana
([Amazon A2I con Textract](https://docs.aws.amazon.com/textract/latest/dg/a2i-textract-core-components.html))
è basato sulla confidenza **per campo**, con soglie separate per campo scelte in base all'impatto
dell'errore, e distingue la confidenza di aver individuato la coppia chiave-valore da quella del
testo contenuto nel valore. La pratica corrente nell'elaborazione documentale converge sullo stesso
punto: un punteggio unico a livello di documento nasconde proprio la variazione che conta.

## Decision

La confidenza di un campo è quella della riga OCR da cui il campo proviene. Il punteggio del
sotto-documento è la confidenza del suo **campo chiave più debole**.

In concreto:

1. **Il dettaglio per riga si conserva.** `TextractOcrAdapter` scrive in ogni pagina di `ocr_pages`
   un elenco `blocks` con testo e confidenza di ciascuna riga. `confidenceAvg` resta dov'era, perché
   alimenta la metrica «Confidenza media OCR» che il Capitolato usa come criterio di accettazione.

2. **Ogni campo trascritto viene ricondotto alla sua riga.** `FieldConfidence` (dominio, logica
   pura) cerca il valore restituito dal modello fra le righe OCR dell'intervallo di pagine del
   sotto-documento, confrontando le due forme normalizzate — maiuscole, accenti sciolti,
   punteggiatura rimossa. Se il valore compare per intero in una riga, quella riga ne porta la
   confidenza; se è spezzato su più righe, il campo prende la **più debole** delle sue parti.

3. **La data si cerca nelle rese in cui il foglio la scrive.** Il modello normalizza sempre a
   `YYYY-MM-DD`, il documento scrive `31/03/2026` o «31 marzo 2026»: senza provare le varianti la
   data risulterebbe sempre non rintracciabile.

4. **Il punteggio è il minimo sui campi chiave**, non la media e non un prodotto. Un documento è
   affidabile quanto il dato meno affidabile fra quelli che lo identificano: se il cognome è
   illeggibile non conta che l'azienda sia nitida. Un campo chiave assente vale zero. Un campo
   presente ma non rintracciabile ricade sulla media di pagina, che in mancanza di meglio resta la
   stima più onesta.

5. **Il codice fiscale ha una soglia propria**, `MVP_FISCAL_CODE_CONFIDENCE_THRESHOLD`, con valore
   predefinito **95**: più alta della soglia generale, perché un carattere letto male assegna il
   documento a un'altra persona, ma tarata su quanto Textract dichiara davvero su quel campo. Si
   applica solo quando un codice fiscale è presente e rintracciabile: un documento che non lo porta
   non viene penalizzato per un dato che non gli compete. Sotto quella soglia il sotto-documento va
   in revisione anche se il punteggio complessivo supera 80.

6. **Le confidenze per campo si persistono**, nella colonna `extracted_data.field_confidences`.
   `confidence_score` ne resta la sintesi: il punteggio dice *se* il documento regge, il dettaglio
   dice *quale campo* non regge. Un valore `null` significa campo non rintracciabile fra le righe,
   che è diverso da campo letto male.

Restano fuori `document_type` e `description`: il modello li compone, non li copia dal foglio, e
cercarli fra le righe non direbbe nulla sulla loro affidabilità.

## Consequences

**Positive.**

- Il caso che motivava la decisione è chiuso: un campo chiave illeggibile in mezzo a una pagina
  pulita ora abbassa il punteggio a quello del campo, e il documento va in revisione.
- Il punteggio torna a discriminare: non è più confinato a pochi valori distanti, ma segue la
  leggibilità effettiva dei dati che contano. UC-38 e UC-40.10 ne guadagnano di significato.
- Il criterio «mapping CF ≥ 99%» del Capitolato è attuato dove serve, invece di essere assorbito in
  una soglia unica.
- La ricerca del valore fra le righe è anche un controllo di aderenza al documento: un valore che il
  foglio non porta risulta non rintracciabile, e si vede nel dettaglio persistito.
- `FieldConfidence` è logica pura senza dipendenze, quindi si prova senza Textract e senza database.

**Negative, o comunque da tenere presenti.**

- **Più documenti finiranno in revisione.** È l'effetto voluto — prima ne passavano di sbagliati —
  ma è un cambio di comportamento visibile sulle metriche, e va comunicato prima di leggerlo come
  un peggioramento.
- **La soglia dedicata al codice fiscale è stata tarata sui documenti, non sulla carta.** La prima
  stesura di questo ADR la fissava a 99, leggendo il «mapping CF ≥ 99%» del Capitolato come una
  soglia per documento. La misura l'ha smentita: su un cedolino reso digitalmente, con ogni campo
  al suo posto, Textract dichiara 99,6 sul nome, 99,4 sull'azienda, 99,8 sulla data e **97,7 sul
  codice fiscale**. Sedici caratteri alfanumerici senza contesto lessicale si leggono un po' peggio
  di una parola, sempre. Con la soglia a 99 nemmeno il documento più pulito possibile si sarebbe
  validato da solo, e l'intero criterio di validazione automatica sarebbe rimasto lettera morta.
  Il valore predefinito è quindi 95: lascia passare un codice nitido e ferma quelli rovinati, che
  stanno molto più in basso. Resta configurabile, ma il default ora poggia su una misura.
- **La corrispondenza è testuale, non posizionale.** Non usa le coordinate dei blocchi: un valore
  che compare in due punti della pagina prende la confidenza dell'occorrenza più leggibile. È una
  semplificazione consapevole; la via posizionale richiederebbe di conservare le geometrie di
  Textract e legarle ai campi.
- **`ocr_pages` cresce.** Ora contiene una voce per riga invece del solo aggregato per pagina.
- **I documenti elaborati prima di questa decisione non hanno `blocks`.** Per loro ogni campo
  risulta non rintracciabile e il calcolo ricade sulla media di pagina, cioè sul comportamento
  precedente. Non serve una migrazione dei dati: serve rielaborare il documento per averne il
  dettaglio.

## Alternatives considered

- **Lasciare la media e progettare intorno al difetto.** Scartata: la formula era documentata e i
  requisiti formalmente soddisfatti, ma il difetto è di sostanza — un documento può essere
  consegnato alla persona sbagliata senza che nulla lo segnali.
- **Sostituire la media con un percentile basso delle righe** (per esempio il decimo). Molto meno
  invasiva, una riga di aggregazione, e avrebbe risolto il caso del campo illeggibile in una pagina
  pulita. Scartata perché resta cieca su *quale* campo sia debole: non alimenta né il dettaglio
  persistito né la soglia dedicata al codice fiscale, e lascia il punteggio senza spiegazione.
- **Usare `AnalyzeDocument` con Queries al posto di `DetectDocumentText`**, facendosi restituire da
  Textract la confidenza per campo. È l'approccio più aderente ad A2I, ma cambia il servizio
  chiamato, il costo per pagina e il contratto dell'adapter OCR, e sposterebbe nel servizio AWS
  l'estrazione che oggi fa Bedrock. Fuori dalla portata di questa decisione; resta la via naturale
  se si vorrà la confidenza posizionale.
- **Media pesata dei campi chiave invece del minimo.** Scartata perché un solo campo illeggibile
  verrebbe di nuovo diluito dagli altri, che è esattamente il difetto da cui si parte.

## Implementation evidence

- `app/Mvp/Documents/Domain/Support/FieldConfidence.php` — attribuzione del valore alla riga,
  normalizzazione, varianti di data.
- `app/Mvp/Documents/Adapters/Outbound/Ocr/TextractOcrAdapter.php` — `blocks` per pagina.
- `app/Mvp/Documents/Application/UseCases/ExtractSubDocumentFieldsService.php` —
  `fieldConfidences()`, `computeConfidenceScore()` (minimo sui campi chiave),
  `reviewStatusForConfidence()` (soglia dedicata al codice fiscale), `ocrBlocksForRange()`.
- `app/Mvp/Documents/Domain/ValueObjects/ExtractedDataChanges.php` — `withFieldConfidences()`.
- `database/migrations/2026_08_20_000000_add_field_confidences_to_extracted_data.php`.
- `config/services.php` — `mvp_fiscal_code_confidence_threshold`.
- `tests/DomainUnit/Documents/FieldConfidenceTest.php` — logica pura.
- `tests/Feature/DocumentExtractionTest.php` — campo illeggibile in pagina pulita, minimo sui campi
  chiave, dettaglio persistito, soglia del codice fiscale.
- `app/Mvp/Support/MvpStateService.php` — `lowConfidenceFields()` nel contratto del documento e
  `fieldConfidenceMetric()`, la ripartizione dei campi estratti fra buona confidenza e da
  revisionare. Sostituisce «campi compilati dall'AI», che contava le caselle piene senza guardare
  quanto fossero leggibili.
- `apps/frontend/src/app/features/copilot/components/field-origin/field-origin.ts` —
  `originForField()`: il glifo del singolo campo, con ricaduta sullo stato del documento per quelli
  elaborati prima di questa decisione.

## Related documents

- [ADR 0010](0010-hexagonal-architecture-documents-communications.md) — colloca `FieldConfidence`
  nel dominio come logica pura, e `Observability` fuori dal perimetro ports & adapters.
- [ADR 0005](0005-no-automatic-fallbacks.md) — nessun fallback silenzioso: il ripiego sulla media di
  pagina è dichiarato e circoscritto ai campi non rintracciabili, non un fallback di servizio.
- `docs/architecture/capitolato-traceability.md` §12 e §13 — soglia di confidenza e
  human-in-the-loop; da aggiornare con la soglia dedicata al codice fiscale.
- `docs/mvp-scope.md` — la descrizione della confidenza va aggiornata: non è più «leggibilità OCR
  ponderata sulla completezza», ma «confidenza del campo chiave più debole».
- `docs/runbooks/document-pipeline.md` punto 8 — stessa correzione.
- Specifica Tecnica §5.1 (repo `Documentazione`, branch `specifica_tecnica`) — da aggiornare a
  partire da questo ADR. Nella stessa sede va corretta la descrizione dell'entità `SubDocument`, che
  dichiara la quarantena come esito di «confidenza troppo bassa» mentre nel codice — e nella
  descrizione del caso d'uso poche righe più avanti — la quarantena è l'esito di un output AI non
  conforme allo schema.
