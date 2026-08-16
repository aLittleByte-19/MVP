import { TestBed } from "@angular/core/testing";
import { GenerationProgressComponent } from "./generation-progress";
import type { CommunicationGenerationPhase } from "../assistant.model";

/**
 * L'adattatore fra le fasi della generazione e la barra condivisa. Le fasi
 * arrivano dagli eventi dello stream SSE dell'AI Assistant.
 */
describe("GenerationProgressComponent", () => {
  function render(phase: CommunicationGenerationPhase | "idle" | null): HTMLElement {
    const fixture = TestBed.createComponent(GenerationProgressComponent);
    fixture.componentRef.setInput("phase", phase);
    fixture.detectChanges();

    return fixture.nativeElement.querySelector("[role=progressbar]") as HTMLElement;
  }

  it.each([
    ["queued", "25"],
    ["generating-text", "55"],
    ["generating-cover", "85"],
    ["still_running", "90"],
    ["completed", "100"],
    ["failed", "100"]
  ] as [CommunicationGenerationPhase, string][])("porta la fase %s al %s per cento", (phase, expected) => {
    expect(render(phase).getAttribute("aria-valuenow")).toBe(expected);
  });

  it.each([[null], ["idle"]] as [CommunicationGenerationPhase | "idle" | null][])(
    "resta a zero quando non c'e' generazione in corso (%s)",
    (phase) => {
      const bar = render(phase);

      expect(bar.getAttribute("aria-valuenow")).toBe("0");
      expect(bar.classList.contains("isActive")).toBe(false);
    }
  );

  it("la copertura mancante non invalida una bozza gia' leggibile", () => {
    // Il testo arriva prima dell'immagine: a "generating-cover" la bozza c'e'
    // gia', e infatti la barra e' quasi piena (ADR 0009).
    expect(Number(render("generating-cover").getAttribute("aria-valuenow"))).toBeGreaterThan(
      Number(render("generating-text").getAttribute("aria-valuenow"))
    );
  });

  it("distingue la generazione fallita da quella conclusa", () => {
    expect(render("failed").classList.contains("isError")).toBe(true);
    expect(render("completed").classList.contains("isDone")).toBe(true);
  });

  it("annuncia di quale avanzamento si tratta", () => {
    expect(render("queued").getAttribute("aria-label")).toBe("Avanzamento generazione della bozza");
  });
});
