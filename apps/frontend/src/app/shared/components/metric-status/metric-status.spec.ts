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

    expect(host.querySelector(".verdict")?.textContent?.trim()).toBe("Nessun errore");
    expect(host.querySelector(".card")?.className).toContain("ok");
  });

  it("mette il conteggio dentro la frase, non accanto", () => {
    // "2" e "da ricaricare" separati costringono a ricomporre la frase: il
    // numero e' parte di quello che c'e' da fare.
    const host = render({
      label: "Elaborazioni non riuscite",
      value: 2,
      issueLabel: "da ricaricare"
    });

    expect(host.querySelector(".verdict")?.textContent?.trim()).toBe("2 da ricaricare");
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
    expect(host.querySelector(".verdict")?.textContent?.trim()).toBe("3 PDF senza immagine");
  });

  it("non afferma nulla finche' il dato non e' arrivato", () => {
    const host = render({ label: "Errori", value: null });

    expect(host.querySelector(".verdict")?.textContent?.trim()).toBe("Non disponibile");
    expect(host.querySelector(".card")?.className).toContain("neutral");
  });
});
