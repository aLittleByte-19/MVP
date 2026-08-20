import { TestBed } from "@angular/core/testing";
import { EXTRACTED_FIELD_KEYS, FieldOriginComponent, originForField, originForReviewStatus } from "./field-origin";

describe("FieldOriginComponent", () => {
  function render(origin: string): HTMLElement {
    const fixture = TestBed.createComponent(FieldOriginComponent);
    fixture.componentRef.setInput("origin", origin);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("accompagna il disegno con un nome, non lo lascia da solo", () => {
    // Il componente vive dentro la <label> del campo: il testo nascosto viene
    // letto come parte dell'etichetta, "Nome e cognome, estratto dall'AI".
    const host = render("auto");

    expect(host.getAttribute("title")).toBe("Estratto dall'AI e validato in automatico");
    expect(host.querySelector(".srOnly")?.textContent?.trim()).toBe(
      "Estratto dall'AI e validato in automatico"
    );
  });

  it("porta la provenienza anche come classe, per il colore del separatore", () => {
    expect(render("manual").className).toContain("manual");
    expect(render("locked").className).toContain("locked");
  });

  it("da' un'icona diversa a ciascuna provenienza", () => {
    // Non solo il colore: la forma sopravvive alla stampa in bianco e nero e
    // alle discromatopsie.
    const glyphOf = (origin: string): string | null =>
      render(origin).querySelector("svg")?.getAttribute("class") ?? null;

    const shapes = new Set(["auto", "manual", "review", "locked"].map(glyphOf));

    expect(shapes.size).toBe(4);
  });
});

describe("originForReviewStatus", () => {
  it("traduce lo stato del sotto-documento nella provenienza dei suoi campi", () => {
    expect(originForReviewStatus("auto_validated")).toBe("auto");
    expect(originForReviewStatus("manually_validated")).toBe("manual");
    expect(originForReviewStatus("needs_review")).toBe("review");
  });

  it("davanti a uno stato ignoto tiene il dato per da verificare", () => {
    // Meglio chiedere una revisione che dichiarare una validazione che non c'è.
    expect(originForReviewStatus(undefined)).toBe("review");
    expect(originForReviewStatus("quarantined")).toBe("review");
  });
});

describe("originForField", () => {
  const inReview = {
    reviewStatus: "needs_review",
    fieldConfidences: { employee_last_name: 41.2, company_name: 96 },
    lowConfidenceFields: ["employee_last_name"]
  };

  it("distingue i campi dubbi da quelli buoni dentro lo stesso documento", () => {
    // E' il motivo per cui la provenienza e' passata dal documento al campo:
    // in un documento in revisione si vede *quale* dato non regge.
    expect(originForField(inReview, EXTRACTED_FIELD_KEYS["employeeName"])).toBe("review");
    expect(originForField(inReview, EXTRACTED_FIELD_KEYS["companyName"])).toBe("auto");
  });

  it("basta una delle due parti del nominativo sotto soglia per marcare la casella", () => {
    // Nome e cognome stanno in un campo solo: il piu' debole comanda.
    expect(EXTRACTED_FIELD_KEYS["employeeName"]).toEqual(["employee_first_name", "employee_last_name"]);
    expect(originForField(inReview, EXTRACTED_FIELD_KEYS["employeeFirstName"])).toBe("auto");
    expect(originForField(inReview, EXTRACTED_FIELD_KEYS["employeeLastName"])).toBe("review");
  });

  it("la conferma dell'operatore vale su tutti i campi del sotto-documento", () => {
    expect(originForField({ ...inReview, reviewStatus: "manually_validated" }, EXTRACTED_FIELD_KEYS["employeeName"])).toBe(
      "manual"
    );
  });

  it("senza dettaglio per campo ricade sullo stato del sotto-documento", () => {
    // Documenti elaborati prima dell'ADR 0013: nessuna confidenza per campo,
    // quindi si torna al comportamento precedente invece di inventare un esito.
    expect(originForField({ reviewStatus: "auto_validated" }, EXTRACTED_FIELD_KEYS["companyName"])).toBe("auto");
    expect(originForField({ reviewStatus: "needs_review" }, EXTRACTED_FIELD_KEYS["companyName"])).toBe("review");
  });

  it("un campo senza controllo corrispondente non ha nulla da cercare", () => {
    // Tipologia e descrizione le compone il modello: non hanno una riga OCR.
    expect(EXTRACTED_FIELD_KEYS["documentType"]).toBeUndefined();
    expect(originForField(inReview, EXTRACTED_FIELD_KEYS["documentType"])).toBe("review");
  });
});
