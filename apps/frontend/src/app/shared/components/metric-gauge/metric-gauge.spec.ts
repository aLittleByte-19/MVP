import { TestBed } from "@angular/core/testing";
import { MetricGaugeComponent } from "./metric-gauge";

describe("MetricGaugeComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricGaugeComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("colloca il valore dentro la scala e ne dichiara gli estremi", () => {
    const host = render({ label: "Confidenza media OCR", value: "98,5", numeric: 98.5, unit: "%" });
    const bounds = Array.from(host.querySelectorAll(".bounds span")).map((node) => node.textContent?.trim());

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("98,5");
    expect(host.querySelector<HTMLElement>(".fill")?.style.width).toBe("98.5%");
    // L'estremo destro porta l'unita': "0 100%" si legge come un intervallo.
    expect(bounds).toEqual(["0", "100%"]);
  });

  it("segna la soglia oltre la quale il sistema decide da solo", () => {
    const host = render({ label: "Confidenza", value: "72,0", numeric: 72, threshold: 80 });

    expect(host.querySelector<HTMLElement>(".tick")?.style.left).toBe("80%");
  });

  it("adatta la scala alla grandezza misurata", () => {
    const host = render({ label: "Media stelle", value: "4,3", numeric: 4.3, max: 5, unit: "/ 5" });

    expect(host.querySelector<HTMLElement>(".fill")?.style.width).toBe("86%");
    expect(host.querySelectorAll(".bounds span")[1]?.textContent?.trim()).toBe("5/ 5");
  });

  it("non riempie nulla quando la misura non c'e'", () => {
    // Un riempimento a zero direbbe "pessima qualita'" dove il dato manca.
    const host = render({ label: "Media stelle", value: "—", numeric: null, max: 5 });

    expect(host.querySelector(".fill")).toBeNull();
    expect(host.querySelector(".num")?.textContent?.trim()).toBe("—");
  });

  it("mostra un segnaposto invece di un numero durante il caricamento", () => {
    const host = render({ label: "Confidenza", value: "—", isLoading: true });

    expect(host.querySelector(".skeleton")).not.toBeNull();
    expect(host.querySelector(".track")).toBeNull();
  });
});
