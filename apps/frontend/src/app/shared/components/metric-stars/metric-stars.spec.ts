import { TestBed } from "@angular/core/testing";
import { MetricStarsComponent } from "./metric-stars";

describe("MetricStarsComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricStarsComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("riempie l'ultima stella per la frazione, senza arrotondarla", () => {
    // E' quel resto a distinguere un 4,3 da un 4,5: arrotondarlo butterebbe
    // via l'unica cifra che stiamo misurando.
    const host = render({ label: "Media stelle", value: "4,3", numeric: 4.3 });
    const widths = Array.from(host.querySelectorAll("defs rect")).map((node) => node.getAttribute("width"));

    expect(widths).toHaveLength(5);
    expect(widths[3]).toBe("24");
    expect(Number(widths[4])).toBeCloseTo(7.2, 1);
  });

  it("dichiara la scala accanto al valore", () => {
    const host = render({ label: "Media stelle", value: "4,3", numeric: 4.3 });

    expect(host.querySelector(".unit")?.textContent?.trim()).toBe("/ 5");
  });

  it("non disegna stelle quando la media non c'e'", () => {
    // Cinque stelle vuote direbbero "pessimo" dove il dato manca.
    const host = render({ label: "Media stelle", value: "—", numeric: null });

    expect(host.querySelector(".stars")).toBeNull();
    expect(host.querySelector(".num")?.textContent?.trim()).toBe("—");
  });

  it("da' a ogni scheda maschere sue, cosi' due schede non si influenzano", () => {
    const first = render({ label: "Media", value: "4,3", numeric: 4.3 });
    const second = render({ label: "Media", value: "2,1", numeric: 2.1 });
    const idOf = (host: HTMLElement): string | null =>
      host.querySelector("defs > *")?.getAttribute("id") ?? null;

    expect(idOf(first)).not.toBe(idOf(second));
  });
});
