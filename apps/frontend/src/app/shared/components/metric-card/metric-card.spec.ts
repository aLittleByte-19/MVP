import { TestBed } from "@angular/core/testing";
import { MetricCardComponent } from "./metric-card";

describe("MetricCardComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricCardComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("lega etichetta e valore in una coppia descrittiva", () => {
    // Prima erano <strong> e <span> affiancati: lo screen reader riceveva due
    // stringhe scollegate invece di "Documenti analizzati: 128".
    const host = render({ label: "Documenti analizzati", value: 128 });

    expect(host.querySelector("dl")).not.toBeNull();
    expect(host.querySelector("dt")?.textContent?.trim()).toBe("Documenti analizzati");
    expect(host.querySelector("dd .num")?.textContent?.trim()).toBe("128");
  });

  it("mostra un segnaposto invece di un numero durante il caricamento", () => {
    // Il difetto chiuso qui: i KPI dell'Overview mostravano 0 mentre lo stato
    // era ancora null, indistinguibile da un conteggio reale a zero.
    const host = render({ label: "Bozze generate", value: null, isLoading: true });

    expect(host.querySelector(".skeleton")).not.toBeNull();
    expect(host.querySelector("dl")?.getAttribute("aria-busy")).toBe("true");
    expect(host.textContent).not.toContain("0");
  });

  it("distingue il valore assente dallo zero", () => {
    const host = render({ label: "Bozze generate", value: null });

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("—");
  });

  it("mostra lo zero quando lo zero e' il dato", () => {
    const host = render({ label: "In quarantena", value: 0 });

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("0");
  });

  it("rende l'unita' separata dal numero", () => {
    const host = render({ label: "Media stelle", value: "4,3", unit: "/ 5" });

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("4,3");
    expect(host.querySelector(".unit")?.textContent?.trim()).toBe("/ 5");
  });

  it("applica il tono come classe, senza affidarsi al solo colore", () => {
    const host = render({ label: "Da verificare", value: 23, tone: "watch" });

    expect(host.querySelector("dl")?.classList.contains("watch")).toBe(true);
  });

  it("disegna una barra per giorno, con oggi in evidenza", () => {
    // Barre e non una linea: la serie e' un flusso di ingressi, sette misure
    // distinte, e una spezzata suggerirebbe un passaggio continuo fra un
    // giorno e l'altro che non esiste.
    const host = render({
      label: "Documenti analizzati",
      value: 128,
      history: [1, 4, 2, 8, 3, 9, 5]
    });
    const bars = host.querySelectorAll("svg.chart rect");

    expect(bars).toHaveLength(7);
    expect(bars[6]?.classList.contains("today")).toBe(true);
    expect(bars[0]?.classList.contains("today")).toBe(false);
    expect(host.querySelector("svg.chart")?.getAttribute("aria-label")).toBe(
      "Andamento degli ultimi 7 giorni per Documenti analizzati"
    );
  });

  it("omette il grafico quando la metrica non ha una serie", () => {
    const host = render({ label: "Campi con confidenza", value: 1284 });

    expect(host.querySelector("svg.chart")).toBeNull();
  });


  it("omette il totale quando la metrica non ne ha uno", () => {
    const host = render({ label: "Bozze da valutare", value: 8 });

    expect(host.querySelector(".total")).toBeNull();
  });

  it("mostra la riga di contesto quando c'e'", () => {
    const host = render({ label: "Da verificare", value: 23, context: "su 412 totali" });

    expect(host.querySelector(".context")?.textContent?.trim()).toBe("su 412 totali");
  });
});
