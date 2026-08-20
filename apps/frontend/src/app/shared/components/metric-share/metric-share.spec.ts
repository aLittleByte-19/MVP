import { TestBed } from "@angular/core/testing";
import { MetricShareComponent } from "./metric-share";

describe("MetricShareComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricShareComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("mette al centro la percentuale e di fianco i due conteggi", () => {
    const host = render({ label: "Sotto-documenti verificati", value: 1207, total: 1284 });

    expect(host.querySelector(".percent")?.textContent?.trim()).toBe("94%");
    expect(host.querySelector(".side")?.textContent).toContain("1.207");
    expect(host.querySelector(".side")?.textContent).toContain("1.284");
    // La descrizione dice il rapporto per esteso: chi ascolta non vede l'anello.
    expect(host.querySelector("svg")?.getAttribute("aria-label")).toBe(
      "Sotto-documenti verificati: 1.207 su 1.284"
    );
  });

  it("colora il residuo come cio' che manca, non come sfondo", () => {
    // E' quello il numero su cui l'operatore deve agire: lasciarlo grigio lo
    // nasconderebbe dietro la parte gia' a posto.
    const host = render({ label: "Verificati", value: 90, total: 100, restNoun: "da verificare" });

    expect(host.querySelector(".rest.plain")).toBeNull();
    expect(host.querySelector("span.rest")?.textContent?.trim()).toBe("10 da verificare");
  });

  it("tiene grigio il resto che non chiede nulla", () => {
    const host = render({
      label: "Bozze valutate",
      value: 197,
      total: 318,
      restTone: "neutral",
      restNoun: "senza voto"
    });

    expect(host.querySelector("circle.rest.plain")).not.toBeNull();
    expect(host.querySelector("span.rest.plain")?.textContent?.trim()).toBe("121 senza voto");
  });

  it("distingue le quote estreme da quelle piene o vuote", () => {
    // Arrotondate direbbero "nessuno" e "tutti" di insiemi che invece hanno
    // ancora un residuo.
    expect(render({ label: "Quota", value: 1, total: 900 }).querySelector(".percent")?.textContent?.trim()).toBe("<1%");
    expect(render({ label: "Quota", value: 899, total: 900 }).querySelector(".percent")?.textContent?.trim()).toBe(">99%");
  });

  it("non disegna alcun anello finche' il totale non e' noto", () => {
    const host = render({ label: "Verificati", value: 23, total: null });

    expect(host.querySelector("circle.done")).toBeNull();
    expect(host.querySelector(".percent")?.textContent?.trim()).toBe("—");
  });

  it("mostra un segnaposto invece di un numero durante il caricamento", () => {
    const host = render({ label: "Verificati", value: null, total: 412, isLoading: true });

    expect(host.querySelector(".skeleton")).not.toBeNull();
    expect(host.querySelector(".figure")).toBeNull();
    expect(host.querySelector("dl")?.getAttribute("aria-busy")).toBe("true");
  });
});
