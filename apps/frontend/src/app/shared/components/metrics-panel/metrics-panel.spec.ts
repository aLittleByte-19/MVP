import { TestBed } from "@angular/core/testing";
import type { Metric } from "../../../../api/generated/model";
import { MetricsPanelComponent } from "./metrics-panel";

describe("MetricsPanelComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricsPanelComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  const metrics: Metric[] = [
    { key: "copilot.needs_review", value: 23, label: "Da verificare", history: [1, 0, 4, 2, 0, 7, 3] },
    { key: "copilot.validated", value: 96, label: "Documenti pronti" }
  ];

  it("rende una metrica per voce di lista", () => {
    const host = render({ metrics });

    expect(host.querySelectorAll("li")).toHaveLength(2);
    expect(host.querySelector("ul")?.getAttribute("aria-label")).toBe("Metriche");
  });

  it("accetta un'etichetta di lista propria, cosi' due pannelli non si confondono", () => {
    const host = render({ metrics, ariaLabel: "Metriche Co-Pilot documentale" });

    expect(host.querySelector("ul")?.getAttribute("aria-label")).toBe("Metriche Co-Pilot documentale");
  });

  it("distingue l'errore dall'assenza di metriche", () => {
    // Prima entrambi i casi mostravano "Nessuna metrica disponibile", cioe' in
    // errore il pannello affermava il falso.
    const host = render({ metrics: [], hasError: true });

    expect(host.textContent).toContain("Metriche non disponibili");
    expect(host.textContent).not.toContain("Nessuna metrica disponibile");
  });

  it("mostra lo stato vuoto quando davvero non ci sono metriche", () => {
    const host = render({ metrics: [] });

    expect(host.textContent).toContain("Nessuna metrica disponibile");
  });

  it("in caricamento espone segnaposto e aria-busy", () => {
    const host = render({ metrics: [], isLoading: true });

    expect(host.querySelector("ul")?.getAttribute("aria-busy")).toBe("true");
    expect(host.querySelectorAll(".skeleton").length).toBeGreaterThan(0);
  });

  it("compone il contesto con il rapporto sul totale e gli ingressi di oggi", () => {
    const host = render({
      metrics,
      presentation: { "copilot.needs_review": { tone: "watch", outOf: 412 } }
    });

    const context = host.querySelector(".context")?.textContent?.trim();

    expect(context).toContain("su 412 totali");
    // La serie e' un flusso di ingresso: si dice "nuovi oggi", non "rispetto a ieri".
    expect(context).toContain("3 nuovi oggi");
  });

  it("usa il singolare quando oggi e' entrato un solo elemento", () => {
    const host = render({
      metrics: [{ key: "k", value: 1, label: "Uno", history: [0, 0, 0, 0, 0, 0, 1] }]
    });

    expect(host.querySelector(".context")?.textContent?.trim()).toBe("1 nuovo oggi");
  });

  it("non inventa un contesto quando non ci sono ne' totale ne' ingressi", () => {
    const host = render({ metrics: [{ key: "k", value: 5, label: "Cinque" }] });

    expect(host.querySelector(".context")).toBeNull();
  });
});
