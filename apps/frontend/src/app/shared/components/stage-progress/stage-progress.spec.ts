import { TestBed } from "@angular/core/testing";
import { type ProgressStage, StageProgressComponent } from "./stage-progress";

describe("StageProgressComponent", () => {
  const stages: readonly ProgressStage[] = [
    { id: "queued", label: "In coda" },
    { id: "processing", label: "Analisi OCR" },
    { id: "completed", label: "Completato" }
  ];

  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(StageProgressComponent);
    fixture.componentRef.setInput("stages", stages);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  function statesOf(host: HTMLElement): string[] {
    return Array.from(host.querySelectorAll("li")).map((item) => item.className);
  }

  it("segna come completate le tappe precedenti a quella corrente", () => {
    const host = render({ currentId: "processing" });

    expect(statesOf(host)).toEqual(["done", "current", "pending"]);
  });

  it("marca la tappa corrente per le tecnologie assistive", () => {
    const host = render({ currentId: "processing" });
    const current = host.querySelector("li.current");

    expect(current?.getAttribute("aria-current")).toBe("step");
    expect(current?.textContent).toContain("Analisi OCR");
  });

  it("lascia tutto in attesa finché la pipeline non è partita", () => {
    const host = render({ currentId: null });

    expect(statesOf(host)).toEqual(["pending", "pending", "pending"]);
  });

  it("colora del fallimento solo la tappa in cui si è verificato", () => {
    // Le precedenti erano riuscite davvero: segnarle come fallite direbbe il falso.
    const host = render({ currentId: "processing", failed: true });

    expect(statesOf(host)).toEqual(["done", "failed", "pending"]);
  });

  it("accompagna ogni stato con un testo, non solo con il colore", () => {
    const host = render({ currentId: "processing" });

    expect(host.textContent).toContain("Completato");
    expect(host.textContent).toContain("In corso");
    expect(host.textContent).toContain("In attesa");
  });

  it("segnala la lentezza senza spostare la tappa", () => {
    const host = render({ currentId: "processing", slow: true });

    expect(statesOf(host)).toEqual(["done", "current", "pending"]);
    expect(host.textContent).toContain("più del previsto");
  });

  it("non annuncia la lentezza quando l'elaborazione è già fallita", () => {
    const host = render({ currentId: "processing", slow: true, failed: true });

    expect(host.textContent).not.toContain("più del previsto");
  });
});
