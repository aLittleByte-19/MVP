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

  it("rende come quota la metrica che porta il proprio totale", () => {
    // Il denominatore arriva dal contratto, non dalla pagina: e' chi calcola
    // il numeratore a sapere su che cosa si misura.
    const host = render({
      metrics: [{ key: "copilot.verified", value: 23, outOf: 412, label: "Verificati" }],
      presentation: { "copilot.verified": { kind: "share" } }
    });

    expect(host.querySelector("mvp-metric-share")).not.toBeNull();
    expect(host.querySelector(".side")?.textContent).toContain("412");
  });

  it("da' alle schede con un grafico due colonne del mosaico", () => {
    // Su una colonna sola una legenda con piu' voci si accavallerebbe.
    const host = render({
      metrics: [
        {
          key: "esito",
          value: 41,
          label: "Esito",
          parts: [
            { label: "Validato", value: 30 },
            { label: "Da rivedere", value: 11 }
          ]
        },
        { key: "conteggio", value: 12, label: "Documenti" }
      ],
      presentation: { esito: { kind: "breakdown", span: 2 } }
    });
    const cells = host.querySelectorAll("li");

    expect(cells[0]?.classList.contains("wide")).toBe(true);
    expect(cells[1]?.classList.contains("wide")).toBe(false);
  });

  it("sceglie la forma della scheda in base alla domanda a cui la metrica risponde", () => {
    const host = render({
      metrics: [
        { key: "media", value: "4.3", unit: "/ 5", label: "Media stelle" },
        { key: "guasti", value: 0, label: "Errori" },
        { key: "conteggio", value: 12, label: "Documenti" }
      ],
      presentation: {
        media: { kind: "stars", max: 5 },
        guasti: { kind: "status", okLabel: "Nessun errore" }
      }
    });

    expect(host.querySelector("mvp-metric-stars .num")?.textContent?.trim()).toBe("4,3");
    expect(host.querySelector("mvp-metric-status")?.textContent).toContain("Nessun errore");
    // Senza indicazioni resta la scheda di andamento, che e' il caso comune.
    expect(host.querySelector("mvp-metric-card .num")?.textContent?.trim()).toBe("12");
  });

  it("colloca la soglia dichiarata dal contratto sulla scala", () => {
    const host = render({
      metrics: [{ key: "ocr", value: "90.0", unit: "%", threshold: 80, label: "Confidenza" }],
      presentation: { ocr: { kind: "gauge" } }
    });

    expect(host.querySelector<HTMLElement>(".tick")?.style.left).toBe("80%");
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
