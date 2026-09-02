import { TestBed } from "@angular/core/testing";
import type { Communication } from "../../../../api/generated/model";
import { CommunicationHistoryListComponent } from "./communication-history-list";

/** Comunicazione minima valida secondo il contratto, personalizzabile. */
function communication(overrides: Partial<Communication> = {}): Communication {
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
    createdAt: "2026-08-17",
    ...overrides
  };
}

describe("CommunicationHistoryListComponent", () => {
  function render(inputs: Record<string, unknown> = {}) {
    const fixture = TestBed.createComponent(CommunicationHistoryListComponent);

    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }

    fixture.detectChanges();

    return fixture;
  }

  it("elenca una scheda per comunicazione", () => {
    const fixture = render({
      communications: [communication(), communication({ id: 2, title: "Chiusura estiva" })]
    });
    const host = fixture.nativeElement as HTMLElement;

    expect(host.querySelectorAll(".card")).toHaveLength(2);
    expect(host.textContent).toContain("Nuova area documentale");
    expect(host.textContent).toContain("Chiusura estiva");
  });

  it("distingue lo storico vuoto dal filtro senza risultati", () => {
    const empty = render({ communications: [] }).nativeElement as HTMLElement;
    expect(empty.textContent).toContain("Le bozze generate compariranno qui.");

    const filtered = render({ communications: [], hasActiveFilters: true })
      .nativeElement as HTMLElement;
    expect(filtered.textContent).toContain("Nessuna comunicazione corrisponde ai filtri selezionati.");
  });

  it("evidenzia la comunicazione selezionata e annuncia la selezione al padre", () => {
    const fixture = render({ communications: [communication()], selectedId: 1 });
    const host = fixture.nativeElement as HTMLElement;
    const selected = jest.fn();
    fixture.componentInstance.selected.subscribe(selected);

    expect(host.querySelector(".card")?.classList.contains("isSelected")).toBe(true);
    expect(host.querySelector(".cardSelect")?.getAttribute("aria-pressed")).toBe("true");

    host.querySelector<HTMLButtonElement>(".cardSelect")?.click();

    expect(selected).toHaveBeenCalledWith(1);
  });

  it("etichetta il preferito in base allo stato corrente e lo propaga intero", () => {
    const fixture = render({ communications: [communication({ isFavorite: true })] });
    const host = fixture.nativeElement as HTMLElement;
    const toggled = jest.fn();
    fixture.componentInstance.favoriteToggled.subscribe(toggled);

    const favorite = host.querySelector<HTMLButtonElement>(".favoriteToggle");

    expect(favorite?.getAttribute("aria-label")).toBe("Rimuovi dai preferiti");
    expect(favorite?.getAttribute("aria-pressed")).toBe("true");

    favorite?.click();

    expect(toggled).toHaveBeenCalledWith(expect.objectContaining({ id: 1, isFavorite: true }));
  });

  it("chiede conferma prima di eliminare e la annulla senza eliminare", () => {
    const fixture = render({ communications: [communication()], confirmingDeleteId: 1 });
    const host = fixture.nativeElement as HTMLElement;
    const confirmRequested = jest.fn();
    const deleteRequested = jest.fn();
    fixture.componentInstance.confirmDeleteRequested.subscribe(confirmRequested);
    fixture.componentInstance.deleteRequested.subscribe(deleteRequested);

    expect(host.textContent).toContain("L'elemento viene rimosso dallo storico in modo definitivo.");

    const [conferma, annulla] = Array.from(
      host.querySelectorAll<HTMLButtonElement>(".cardActions button")
    );
    annulla?.click();
    expect(confirmRequested).toHaveBeenCalledWith(null);
    expect(deleteRequested).not.toHaveBeenCalled();

    conferma?.click();
    expect(deleteRequested).toHaveBeenCalledWith(1);
  });

  it("mostra la conferma solo sulla riga che la sta chiedendo", () => {
    const host = render({
      communications: [communication(), communication({ id: 2 })],
      confirmingDeleteId: 2
    }).nativeElement as HTMLElement;

    expect(host.querySelectorAll(".cardConfirm")).toHaveLength(1);
  });
});
