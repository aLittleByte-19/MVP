import { TestBed } from "@angular/core/testing";
import { FieldOriginComponent, originForReviewStatus } from "./field-origin";

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
