import { TestBed } from "@angular/core/testing";
import { MetricStatusComponent } from "./metric-status";

describe("MetricStatusComponent", () => {
  function render(inputs: Record<string, unknown>): HTMLElement {
    const fixture = TestBed.createComponent(MetricStatusComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it("legge lo zero come la notizia buona che e'", () => {
    const host = render({ label: "Elaborazioni non riuscite", value: 0, okLabel: "Nessun errore" });

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("0");
    expect(host.querySelector(".badge")?.textContent).toContain("Nessun errore");
    expect(host.querySelector(".card")?.className).toContain("ok");
  });

  it("dice che cosa fare quando un caso c'e'", () => {
    const host = render({
      label: "Elaborazioni non riuscite",
      value: 2,
      issueLabel: "Da ricaricare"
    });

    expect(host.querySelector(".badge")?.textContent).toContain("Da ricaricare");
    expect(host.querySelector(".card")?.className).toContain("alert");
  });

  it("distingue cio' che degrada da cio' che blocca", () => {
    // Una copertina mancante non ferma il PDF: e' un avviso, non un guasto.
    const host = render({
      label: "Copertine non riuscite",
      value: 3,
      issueTone: "warning",
      issueLabel: "PDF senza immagine"
    });

    expect(host.querySelector(".card")?.className).toContain("watch");
    expect(host.querySelector(".badge")?.className).toContain("warning");
  });

  it("non afferma nulla finche' il dato non e' arrivato", () => {
    const host = render({ label: "Errori", value: null });

    expect(host.querySelector(".num")?.textContent?.trim()).toBe("—");
    expect(host.querySelector(".badge")?.textContent).toContain("Non disponibile");
    expect(host.querySelector(".card")?.className).toContain("neutral");
  });
});
