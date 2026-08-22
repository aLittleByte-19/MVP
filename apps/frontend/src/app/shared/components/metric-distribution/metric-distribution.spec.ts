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

  it("disegna la curva anche su pochi campioni, dicendo quanti sono", () => {
    // La forma la da' l'interpolazione piu' che i dati, ma resta una forma: il
    // conteggio sotto l'asse dice quanto pesarla.
    const host = render({ label: "Durata", buckets, sampleSize: 3 });

    expect(host.querySelector("path.line")).not.toBeNull();
    expect(host.querySelector(".samples")?.textContent?.trim()).toBe("Su 3 elaborazioni concluse");
  });

  it("senza misure lascia la griglia vuota invece di una frase al posto del grafico", () => {
    const host = render({ label: "Durata", buckets: undefined, sampleSize: 0 });

    expect(host.querySelector("path.line")).toBeNull();
    expect(host.querySelectorAll("svg.empty line.grid").length).toBeGreaterThan(3);
    expect(host.querySelector(".samples")?.textContent?.trim()).toBe(
      "Nessuna elaborazione conclusa negli ultimi sette giorni"
    );
  });

  it("chiama le corse con il nome che hanno nel modulo", () => {
    const host = render({ label: "Durata", buckets, sampleSize: 12, subject: "generazioni" });

    expect(host.querySelector(".samples")?.textContent?.trim()).toBe("Su 12 generazioni concluse");
  });
});
