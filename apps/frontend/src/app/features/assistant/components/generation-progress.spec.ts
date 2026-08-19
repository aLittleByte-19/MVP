import { TestBed } from "@angular/core/testing";
import type { CommunicationGenerationPhase } from "../assistant.model";
import { GenerationProgressComponent } from "./generation-progress";

/**
 * L'adattatore fra le fasi della generazione e l'avanzamento per tappe. Le
 * fasi arrivano dagli eventi dello stream SSE dell'AI Assistant: qui si
 * verifica solo che ciascuna cada sulla tappa giusta, non una percentuale —
 * quelle erano una scala fissa che non misurava alcun avanzamento reale.
 */
describe("GenerationProgressComponent", () => {
  function render(phase: CommunicationGenerationPhase | "idle" | null): HTMLElement {
    const fixture = TestBed.createComponent(GenerationProgressComponent);
    fixture.componentRef.setInput("phase", phase);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  function currentStage(host: HTMLElement): string | null {
    return host.querySelector("li.current .name")?.textContent?.trim() ?? null;
  }

  it.each([
    ["queued", "In coda"],
    ["generating-text", "Testo"],
    ["generating-cover", "Copertina"]
  ] as [CommunicationGenerationPhase, string][])("colloca la fase %s sulla tappa %s", (phase, expected) => {
    expect(currentStage(render(phase))).toBe(expected);
  });

  it.each([[null], ["idle"]] as [CommunicationGenerationPhase | "idle" | null][])(
    "non evidenzia alcuna tappa quando non c'e' generazione in corso (%s)",
    (phase) => {
      const host = render(phase);

      expect(host.querySelector("li.current")).toBeNull();
      expect(host.querySelectorAll("li.pending")).toHaveLength(4);
    }
  );

  it("resta sulla copertina quando la generazione tarda, segnalandolo", () => {
    // `still_running` non e' una tappa: la pipeline e' ferma dov'era, da piu'
    // tempo del previsto.
    const host = render("still_running");

    expect(currentStage(host)).toBe("Copertina");
    expect(host.textContent).toContain("più del previsto");
  });

  it("distingue la generazione fallita da quella conclusa", () => {
    const failed = render("failed");

    expect(failed.querySelector("li.failed .name")?.textContent?.trim()).toBe("Copertina");
    expect(failed.querySelector("li.current")).toBeNull();

    // A generazione conclusa nessuna tappa resta in corso: l'ultima e'
    // completata, non un passo ancora aperto.
    const completed = render("completed");

    expect(completed.querySelector("li.failed")).toBeNull();
    expect(currentStage(completed)).toBeNull();
    expect(completed.querySelector("li:last-child")?.className).toBe("done");
  });

  it("la copertina mancante non invalida una bozza gia' leggibile", () => {
    // Il testo precede la copertina: a quel punto la bozza e' consultabile,
    // quindi la tappa del testo resta completata anche se la copertina fallisce.
    const host = render("failed");
    const done = Array.from(host.querySelectorAll("li.done .name")).map((node) =>
      node.textContent?.trim()
    );

    expect(done).toEqual(["In coda", "Testo"]);
  });

  it("annuncia di quale avanzamento si tratta", () => {
    expect(render("queued").querySelector("ol")?.getAttribute("aria-label")).toBe(
      "Avanzamento generazione della bozza"
    );
  });
});
