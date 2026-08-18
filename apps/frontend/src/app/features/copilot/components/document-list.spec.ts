import { TestBed } from "@angular/core/testing";
import type { SubDocument } from "../../../../api/generated/model";
import { DocumentListComponent } from "./document-list";

/** Sotto-documento minimo valido secondo il contratto, personalizzabile. */
function subDocument(overrides: Partial<SubDocument> = {}): SubDocument {
  return {
    id: "sub-1",
    employee: "Mario Rossi",
    companyName: "Acme SpA",
    title: "Cedolino marzo",
    file: "cedolini.pdf",
    documentDate: "2026-03-31",
    confidence: 92,
    reviewStatus: "auto_validated",
    reviewStatusLabel: "Validato automaticamente",
    sendStatus: "pending",
    sendStatusLabel: "Non scaricato",
    previewLines: [],
    ...overrides
  };
}

describe("DocumentListComponent", () => {
  function render(inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(DocumentListComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture;
  }

  it("mostra un messaggio di cortesia quando non c'e' nulla da elencare", () => {
    const element = render().nativeElement as HTMLElement;

    expect(element.querySelector("table")).toBeNull();
    expect(element.textContent).toContain("I documenti caricati compariranno qui.");
  });

  it("rispetta il messaggio di vuoto passato dal chiamante", () => {
    // Lo storico filtrato deve poter dire "nessun risultato per questi filtri",
    // che e' un'informazione diversa da "non hai ancora caricato niente".
    const element = render({ documents: [], emptyMessage: "Nessun documento per i filtri scelti." })
      .nativeElement as HTMLElement;

    expect(element.textContent).toContain("Nessun documento per i filtri scelti.");
  });

  it("elenca una riga per documento", () => {
    const element = render({
      documents: [subDocument(), subDocument({ id: "sub-2", employee: "Laura Bianchi" })]
    }).nativeElement as HTMLElement;

    expect(element.querySelectorAll("tbody tr")).toHaveLength(2);
    expect(element.textContent).toContain("Mario Rossi");
    expect(element.textContent).toContain("Laura Bianchi");
  });

  it("ripiega su un testo leggibile quando il dato non e' stato estratto", () => {
    // Una cella vuota lascerebbe credere a un errore di caricamento: meglio
    // dire esplicitamente che il dato manca.
    const element = render({
      documents: [subDocument({ employee: null, companyName: null, confidence: null })]
    }).nativeElement as HTMLElement;

    expect(element.textContent).toContain("Destinatario rilevato");
    expect(element.textContent).toContain("Da verificare");
  });

  it("usa la tipologia quando manca il titolo", () => {
    const element = render({
      documents: [subDocument({ title: null, documentType: "cedolino" })]
    }).nativeElement as HTMLElement;

    expect(element.textContent).toContain("cedolino");
  });

  it("mostra le etichette di stato che arrivano dal backend", () => {
    const element = render({
      documents: [
        subDocument({ reviewStatusLabel: "In quarantena", sendStatusLabel: "Scaricato" })
      ]
    }).nativeElement as HTMLElement;

    expect(element.textContent).toContain("In quarantena");
    expect(element.textContent).toContain("Scaricato");
  });

  it("tiene nelle colonne i dati dell'analisi e nella prima cella il documento di partenza", () => {
    // La riga si legge come un documento in lavorazione, non come un elenco di
    // nove campi alla pari: azienda, nome del file e data del documento
    // stanno sotto al destinatario.
    const element = render({ documents: [subDocument()] }).nativeElement as HTMLElement;
    const columns = [...element.querySelectorAll("thead th")].map((th) =>
      th.getAttribute("data-column")
    );

    expect(columns).toEqual([
      "recipient",
      "type",
      "uploadedAt",
      "confidence",
      "review",
      "download",
      "actions"
    ]);

    const recipient = element.querySelector('td[data-column="recipient"]');

    expect(recipient?.textContent).toContain("Acme SpA");
    expect(recipient?.textContent).toContain("cedolini.pdf");
  });

  it("distingue lo stato di scaricamento da quello di validazione", () => {
    // Due domande diverse, due forme diverse: rettangolo per la revisione,
    // pallino pieno o vuoto per il download.
    const element = render({
      documents: [subDocument({ sendStatus: "sent", sendStatusLabel: "Scaricato" })]
    }).nativeElement as HTMLElement;

    expect(element.querySelector('td[data-column="review"] .badge')).not.toBeNull();
    expect(element.querySelector('td[data-column="download"] .indicator')?.classList).toContain(
      "done"
    );
  });

  it("evidenzia il documento selezionato", () => {
    const fixture = render({ documents: [subDocument(), subDocument({ id: "sub-2" })] });
    fixture.componentRef.setInput("selectedDocumentId", "sub-2");
    fixture.detectChanges();

    const buttons = [...(fixture.nativeElement as HTMLElement).querySelectorAll("button.action")];

    expect(buttons[0].className).not.toContain("primary");
    expect(buttons[1].className).toContain("primary");
  });

  it("annuncia al padre quale documento e' stato scelto", () => {
    const fixture = render({ documents: [subDocument({ id: "sub-7" })] });
    const selected: string[] = [];
    fixture.componentInstance.selectDocument.subscribe((id) => selected.push(id));

    (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>("button.action")?.click();

    expect(selected).toEqual(["sub-7"]);
  });
});
