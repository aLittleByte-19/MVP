import { TestBed } from "@angular/core/testing";
import { MetricBreakdownComponent } from "./metric-breakdown";

describe("MetricBreakdownComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricBreakdownComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  const parts = [
    { label: "Da revisionare", value: 3, tone: "warning" },
    { label: "Validato automaticamente", value: 12, tone: "info" },
    { label: "Validato manualmente", value: 5, tone: "success" }
  ];

  it("tiene distinti gli stati invece di schiacciarli in una percentuale", () => {
    // "85% verificati" mette insieme cio' che il sistema ha validato da solo e
    // cio' che una persona ha confermato: per l'operatore sono due cose diverse.
    const host = render({ label: "Esito della revisione", parts });
    const arcs = host.querySelectorAll("svg circle");

    expect(arcs).toHaveLength(3);
    expect(host.querySelector(".total")?.textContent?.trim()).toBe("20");
    expect(host.querySelectorAll(".legend li")).toHaveLength(3);
  });

  it("prende il colore di ogni stato dal contratto, non da una tabella sua", () => {
    const host = render({ label: "Esito", parts });
    const tones = Array.from(host.querySelectorAll("svg circle")).map((node) => node.getAttribute("class"));

    expect(tones).toEqual(["warning", "info", "success"]);
  });

  it("dispone gli archi uno dopo l'altro sulla circonferenza", () => {
    // Il primo parte da zero, ciascun altro dalla fine del precedente: e' lo
    // scarto a rendere la corona segmentata invece di tre cerchi sovrapposti.
    const host = render({ label: "Esito", parts });
    const offsets = Array.from(host.querySelectorAll("svg circle")).map((node) =>
      Number(node.getAttribute("stroke-dashoffset"))
    );

    expect(offsets[0]).toBe(0);
    expect(offsets[1]).toBeLessThan(0);
    expect(offsets[2]).toBeLessThan(offsets[1]!);
  });

  it("dice la ripartizione per esteso a chi non vede l'anello", () => {
    const host = render({ label: "Esito della revisione", parts });

    expect(host.querySelector("svg")?.getAttribute("aria-label")).toBe(
      "Esito della revisione su 20: Da revisionare 3, Validato automaticamente 12, Validato manualmente 5"
    );
  });

  it("non disegna un anello vuoto quando non c'e' nulla da ripartire", () => {
    const host = render({ label: "Esito", parts: [], emptyLabel: "Nessun sotto-documento elaborato." });

    expect(host.querySelector("svg")).toBeNull();
    expect(host.querySelector(".none")?.textContent?.trim()).toBe("Nessun sotto-documento elaborato.");
  });
});
