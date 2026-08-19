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

  it("dice il rapporto per esteso e lo disegna come porzione", () => {
    const host = render({ label: "Da verificare", value: 23, total: 412 });
    const total = host.querySelector(".total");

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("23");
    expect(total?.textContent).toContain("412");
    // La barra e' solo un segno grafico: chi usa uno screen reader sente
    // "Da verificare: 23 su 412", non "23 slash 412".
    expect(total?.querySelector("[aria-hidden='true']")?.textContent).toBe("/");
    expect(total?.querySelector(".srOnly")?.textContent?.trim()).toBe("su");
    expect(host.querySelector(".bar")?.getAttribute("aria-hidden")).toBe("true");
    expect(host.querySelector<HTMLElement>(".fill")?.style.width).toMatch(/^5\.58/);
    expect(host.querySelector(".percent")?.textContent?.trim()).toBe("6%");
  });

  it("distingue una quota minima da nessuna quota", () => {
    // Arrotondata darebbe "0%", cioe' "nessuno" di qualcosa che invece c'e'.
    expect(render({ label: "In quarantena", value: 1, total: 900 }).querySelector(".percent")?.textContent?.trim()).toBe(
      "<1%"
    );
  });

  it("non disegna alcuna porzione finche' il totale non e' noto", () => {
    const host = render({ label: "Da verificare", value: 23, total: null });

    expect(host.querySelector(".fill")).toBeNull();
    expect(host.querySelector(".total")?.textContent).toContain("—");
  });

  it("mostra un segnaposto invece di un numero durante il caricamento", () => {
    const host = render({ label: "Da verificare", value: null, total: 412, isLoading: true });

    expect(host.querySelector(".skeleton")).not.toBeNull();
    expect(host.querySelector(".lead")).toBeNull();
    expect(host.querySelector("dl")?.getAttribute("aria-busy")).toBe("true");
  });
});
