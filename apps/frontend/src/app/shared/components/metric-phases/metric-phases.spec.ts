import { TestBed } from "@angular/core/testing";
import { MetricPhasesComponent } from "./metric-phases";

describe("MetricPhasesComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricPhasesComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  const parts = [
    { label: "OCR", value: 28 },
    { label: "Estrazione", value: 15 },
    { label: "Orchestrazione", value: 4 }
  ];

  it("ripartisce il totale fra le fasi in proporzione", () => {
    const host = render({ label: "Tempo medio", value: "47", unit: "s", parts });
    const widths = Array.from(host.querySelectorAll<HTMLElement>(".seg")).map((node) => node.style.width);

    expect(widths).toEqual(["59.6%", "31.9%", "8.5%"]);
    expect(host.querySelector(".num")?.textContent?.trim()).toBe("47");
  });

  it("scrive i secondi accanto a ogni fase, non solo la proporzione", () => {
    // La barra dice quale pesa di piu', la legenda dice quanto: per decidere
    // dove intervenire serve il numero.
    const host = render({ label: "Tempo medio", value: "47", unit: "s", parts });

    expect(host.querySelector(".legend")?.textContent).toContain("OCR");
    expect(host.querySelector(".legend")?.textContent).toContain("28s");
    expect(host.querySelector(".bar")?.getAttribute("aria-label")).toBe(
      "Tempo medio: OCR 28s, Estrazione 15s, Orchestrazione 4s"
    );
  });

  it("resta un tempo medio quando le fasi non sono note", () => {
    const host = render({ label: "Tempo medio", value: "47", unit: "s" });

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("47");
    expect(host.querySelector(".bar")).toBeNull();
  });

  it("mostra un segnaposto invece di un numero durante il caricamento", () => {
    const host = render({ label: "Tempo medio", value: "—", isLoading: true });

    expect(host.querySelector(".skeleton")).not.toBeNull();
    expect(host.querySelector(".lead")).toBeNull();
  });
});
