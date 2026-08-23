import { TestBed } from "@angular/core/testing";
import type { Communication } from "../../../../api/generated/model";
import { RecentFeedbackListComponent } from "./recent-feedback-list";

/** Comunicazione valutata minima, personalizzabile. */
function ratedCommunication(overrides: Partial<Communication> = {}): Communication {
  return {
    id: 1,
    prompt: "Comunica la nuova area documentale",
    tone: "Chiaro e diretto",
    style: "Testo informativo",
    title: "Nuova area documentale",
    previewUrl: "/api/v1/communications/1/preview",
    exportUrl: "/api/v1/communications/1/export",
    coverStatus: "ready",
    coverStatusLabel: "Pronta",
    generationStatus: "completed",
    generationStatusLabel: "Completata",
    status: "Approvata",
    isFavorite: false,
    rating: 4,
    ratingComment: "Testo chiaro e utile.",
    ratedAt: "17/08/2026 10:00",
    ...overrides
  };
}

describe("RecentFeedbackListComponent", () => {
  function render(inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(RecentFeedbackListComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture;
  }

  it("elenca una scheda per feedback, con voto e commento", () => {
    const host = render({ feedback: [ratedCommunication()] }).nativeElement as HTMLElement;

    expect(host.querySelectorAll(".card")).toHaveLength(1);
    expect(host.textContent).toContain("Nuova area documentale");
    expect(host.textContent).toContain("Testo chiaro e utile.");
    expect(host.textContent).toContain("4/5");
  });

  it("omette il commento quando assente, senza omettere la scheda", () => {
    const host = render({
      feedback: [ratedCommunication({ ratingComment: null })]
    }).nativeElement as HTMLElement;

    expect(host.querySelectorAll(".card")).toHaveLength(1);
    expect(host.querySelector(".comment")).toBeNull();
  });

  it("mostra lo stato vuoto quando non c'e' ancora nessun feedback", () => {
    const host = render({ feedback: [] }).nativeElement as HTMLElement;

    expect(host.textContent).toContain("Nessun feedback ricevuto finora.");
  });

  it("etichetta ogni voto col titolo del contenuto, cosi' schede multiple restano distinguibili per uno screen reader", () => {
    const host = render({
      feedback: [
        ratedCommunication({ id: 1, title: "Nuova area documentale" }),
        ratedCommunication({ id: 2, title: "Chiusura estiva" })
      ]
    }).nativeElement as HTMLElement;

    const legends = Array.from(host.querySelectorAll("legend")).map((legend) => legend.textContent);

    expect(legends).toEqual(["Voto per Nuova area documentale", "Voto per Chiusura estiva"]);
  });

  it("non lascia 'null' nell'etichetta quando il contenuto non ha titolo", () => {
    const host = render({
      feedback: [ratedCommunication({ title: null })]
    }).nativeElement as HTMLElement;

    expect(host.querySelector("legend")?.textContent).toBe("Voto per contenuto");
  });
});
