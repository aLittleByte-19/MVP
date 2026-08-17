import { TestBed } from "@angular/core/testing";
import { ProgressBarComponent, type ProgressState } from "./progress-bar";

describe("ProgressBarComponent", () => {
  /** Rende la barra con i valori dati e restituisce l'elemento con il ruolo ARIA. */
  function render(value: number, state?: ProgressState, label?: string): HTMLElement {
    const fixture = TestBed.createComponent(ProgressBarComponent);
    fixture.componentRef.setInput("value", value);

    if (state) {
      fixture.componentRef.setInput("state", state);
    }

    if (label) {
      fixture.componentRef.setInput("label", label);
    }

    fixture.detectChanges();

    return fixture.nativeElement.querySelector("[role=progressbar]") as HTMLElement;
  }

  it("espone l'avanzamento alle tecnologie assistive", () => {
    const bar = render(40, "active", "Avanzamento elaborazione documento");

    expect(bar.getAttribute("aria-valuenow")).toBe("40");
    expect(bar.getAttribute("aria-valuemin")).toBe("0");
    expect(bar.getAttribute("aria-valuemax")).toBe("100");
    expect(bar.getAttribute("aria-label")).toBe("Avanzamento elaborazione documento");
  });

  it("ha un'etichetta di default, cosi' non resta mai muta", () => {
    expect(render(0).getAttribute("aria-label")).toBe("Avanzamento elaborazione");
  });

  it.each([
    [-20, "0%"],
    [0, "0%"],
    [55, "55%"],
    [100, "100%"],
    [140, "100%"]
  ])("riporta il valore %i dentro i limiti della barra", (value, expected) => {
    // Un valore fuori scala disegnerebbe un riempimento piu' largo della
    // traccia: la barra si difende da sola invece di fidarsi del chiamante.
    const fill = render(value).querySelector(".fill") as HTMLElement;

    expect(fill.style.width).toBe(expected);
  });

  it.each([
    [-20, "0"],
    [140, "100"]
  ])("annuncia il valore clampato, non quello grezzo (%i)", (value, expected) => {
    // aria-valuenow leggeva il valore grezzo mentre il riempimento usava quello
    // clampato: con un input fuori scala lo screen reader annunciava un numero
    // diverso da quello mostrato.
    expect(render(value).getAttribute("aria-valuenow")).toBe(expected);
  });

  it.each([
    ["idle", []],
    ["active", ["isActive"]],
    ["done", ["isDone"]],
    ["error", ["isError"]]
  ] as [ProgressState, string[]][])("nello stato %s applica le classi attese", (state, classes) => {
    const bar = render(50, state);

    for (const expected of ["isActive", "isDone", "isError"]) {
      expect(bar.classList.contains(expected)).toBe(classes.includes(expected));
    }
  });

  it("parte da fermo quando lo stato non viene passato", () => {
    const bar = render(0);

    expect(bar.classList.contains("isActive")).toBe(false);
  });
});
