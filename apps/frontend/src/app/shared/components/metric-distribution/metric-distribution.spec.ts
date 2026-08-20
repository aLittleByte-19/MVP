import { TestBed } from "@angular/core/testing";
import { MetricDistributionComponent } from "./metric-distribution";

describe("MetricDistributionComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricDistributionComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  const buckets = [
    { upTo: 5, count: 2 },
    { upTo: 10, count: 9 },
    { upTo: 15, count: 23 },
    { upTo: 20, count: 11 },
    { upTo: 25, count: 4 },
    { upTo: 30, count: 1 }
  ];

  it("disegna la curva e infittisce l'asse dei tempi", () => {
    const host = render({ label: "Durata delle elaborazioni", buckets, sampleSize: 50 });

    expect(host.querySelector("path.line")).not.toBeNull();
    expect(host.querySelector("path.area")).not.toBeNull();
    // Le verticali danno il riferimento per stimare dove cade il picco.
    expect(host.querySelectorAll("line.grid").length).toBeGreaterThan(3);
    expect(host.querySelector(".axis")?.textContent).toContain("30s");
  });

  it("dice a parole dove sta la massa, per chi non vede la curva", () => {
    const host = render({ label: "Durata", buckets, sampleSize: 50 });

    expect(host.querySelector("svg")?.getAttribute("aria-label")).toContain("15s");
    expect(host.querySelector("svg")?.getAttribute("aria-label")).toContain("23 elaborazioni");
  });

  it("non disegna una curva che i dati non reggono", () => {
    // Con pochi campioni la forma la darebbe l'interpolazione, non le misure.
    const host = render({ label: "Durata", buckets, sampleSize: 3, minSamples: 8 });

    expect(host.querySelector("path.line")).toBeNull();
    expect(host.querySelector(".few")?.textContent).toContain("3 elaborazioni");
  });

  it("distingue il caso senza misure da quello con poche", () => {
    const host = render({ label: "Durata", buckets: undefined, sampleSize: 0 });

    expect(host.querySelector(".few")?.textContent?.trim()).toBe(
      "Nessuna elaborazione conclusa negli ultimi sette giorni."
    );
  });

  it("chiama le corse con il nome che hanno nel modulo", () => {
    const host = render({ label: "Durata", buckets, sampleSize: 0, subject: "generazioni" });

    expect(host.querySelector(".few")?.textContent?.trim()).toBe(
      "Nessuna generazione conclusa negli ultimi sette giorni."
    );
  });
});
