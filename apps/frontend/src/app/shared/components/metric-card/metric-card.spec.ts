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

  it("disegna la sparkline con una descrizione accessibile", () => {
    const host = render({
      label: "Da verificare",
      value: 23,
      history: [1, 0, 4, 2, 0, 7, 3]
    });

    const spark = host.querySelector("svg.spark");

    expect(spark).not.toBeNull();
    expect(spark?.getAttribute("aria-label")).toContain("ultimi 7 giorni");
    expect(spark?.querySelector("polyline")?.getAttribute("points")).toBeTruthy();
  });

  it("omette la sparkline quando la metrica non ha una serie", () => {
    const host = render({ label: "Campi con confidenza", value: 1284 });

    expect(host.querySelector("svg.spark")).toBeNull();
  });

  it("tiene il totale di riferimento sulla riga del valore, non in quella di contesto", () => {
    const host = render({ label: "Da verificare", value: 23, outOf: 412 });
    const total = host.querySelector(".total");

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("23");
    expect(total?.textContent).toContain("412");
    // La barra e' solo un segno grafico: chi usa uno screen reader sente
    // "Da verificare: 23 su 412", non "23 slash 412".
    expect(total?.querySelector("[aria-hidden='true']")?.textContent).toBe("/");
    expect(total?.querySelector(".srOnly")?.textContent?.trim()).toBe("su");
    expect(host.querySelector(".context")).toBeNull();
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
