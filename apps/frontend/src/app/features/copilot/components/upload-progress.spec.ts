import { TestBed } from "@angular/core/testing";
import type { DocumentUploadPhase } from "../data/document-workflow.service";
import { UploadProgressComponent } from "./upload-progress";

/**
 * L'adattatore fra le fasi della pipeline documentale e l'avanzamento per
 * tappe. Le fasi arrivano dagli eventi reali dello stream SSE, quindi la
 * mappatura non e' cosmetica: se sbaglia, l'interfaccia racconta uno stato
 * che il documento non ha.
 */
describe("UploadProgressComponent", () => {
  function render(phase: DocumentUploadPhase | null): HTMLElement {
    const fixture = TestBed.createComponent(UploadProgressComponent);
    fixture.componentRef.setInput("phase", phase);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  function currentStage(host: HTMLElement): string | null {
    return host.querySelector("li.current .name")?.textContent?.trim() ?? null;
  }

  it.each([
    ["uploading", "Caricamento"],
    ["queued", "In coda"],
    ["processing", "Analisi OCR"],
    ["extracting", "Estrazione campi"],
    ["completed", "Completato"]
  ] as [DocumentUploadPhase, string][])("colloca la fase %s sulla tappa %s", (phase, expected) => {
    expect(currentStage(render(phase))).toBe(expected);
  });

  it("non evidenzia alcuna tappa prima che l'elaborazione inizi", () => {
    const host = render(null);

    expect(host.querySelector("li.current")).toBeNull();
    expect(host.querySelectorAll("li.pending")).toHaveLength(5);
  });

  it("resta sull'estrazione quando l'elaborazione tarda, segnalandolo", () => {
    // `still_running` non e' una tappa: il documento e' fermo dov'era, da piu'
    // tempo del previsto, e la lavorazione prosegue in background.
    const host = render("still_running");

    expect(currentStage(host)).toBe("Estrazione campi");
    expect(host.textContent).toContain("più del previsto");
  });

  it("distingue l'elaborazione fallita da quella conclusa", () => {
    const failed = render("failed");

    expect(failed.querySelector("li.failed .name")?.textContent?.trim()).toBe("Estrazione campi");
    expect(failed.textContent).not.toContain("più del previsto");

    expect(render("completed").querySelector("li.failed")).toBeNull();
  });

  it("tiene per completate le tappe superate prima del fallimento", () => {
    const done = Array.from(render("failed").querySelectorAll("li.done .name")).map((node) =>
      node.textContent?.trim()
    );

    expect(done).toEqual(["Caricamento", "In coda", "Analisi OCR"]);
  });

  it("annuncia di quale elaborazione si tratta", () => {
    expect(render("queued").querySelector("ol")?.getAttribute("aria-label")).toBe(
      "Avanzamento elaborazione documento"
    );
  });
});
