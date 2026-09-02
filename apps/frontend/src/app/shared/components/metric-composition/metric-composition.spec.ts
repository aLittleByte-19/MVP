import { TestBed } from "@angular/core/testing";
import { MetricCompositionComponent } from "./metric-composition";

describe("MetricCompositionComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricCompositionComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  const parts = [
    { label: "Validati automaticamente", value: 289 },
    { label: "Validati a mano", value: 86 },
    { label: "Da verificare", value: 23 },
    { label: "In quarantena", value: 14 }
  ];

  it("dimensiona ogni segmento in proporzione al totale", () => {
    const host = render({ parts });
    const segments = host.querySelectorAll<HTMLElement>(".seg");

    expect(segments).toHaveLength(4);
    // 289 su 412 = 70,1%
    expect(segments[0]!.style.width.startsWith("70.")).toBe(true);
  });

  it("descrive la ripartizione alle tecnologie assistive", () => {
    const host = render({ parts, subject: "sotto-documenti" });
    const label = host.querySelector(".bar")?.getAttribute("aria-label");

    expect(label).toContain("412 sotto-documenti");
    expect(label).toContain("Da verificare 23");
  });

  it("omette le parti a zero, che non hanno un segmento disegnabile", () => {
    const host = render({
      parts: [
        { label: "Presenti", value: 10 },
        { label: "Assenti", value: 0 }
      ]
    });

    expect(host.querySelectorAll(".seg")).toHaveLength(1);
    expect(host.textContent).not.toContain("Assenti");
  });

  it("mostra un messaggio quando non c'e' nulla da ripartire", () => {
    const host = render({
      parts: [{ label: "Presenti", value: 0 }],
      emptyLabel: "Nessun sotto-documento ancora elaborato."
    });

    expect(host.querySelector(".bar")).toBeNull();
    expect(host.textContent).toContain("Nessun sotto-documento ancora elaborato.");
  });

  it("non supera le cinque tonalita' disponibili", () => {
    const many = Array.from({ length: 7 }, (_, index) => ({ label: `P${index}`, value: 1 }));
    const host = render({ parts: many });
    const backgrounds = Array.from(host.querySelectorAll<HTMLElement>(".seg")).map(
      (segment) => segment.style.background
    );

    expect(backgrounds.every((background) => /mvp-series-[1-5]/.test(background))).toBe(true);
  });
});
