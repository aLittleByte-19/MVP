import { TestBed } from "@angular/core/testing";
import { Router } from "@angular/router";
import { signal } from "@angular/core";
import type { Metric, MvpState } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { OverviewPage } from "./overview-page";
import { OverviewPageViewModel } from "./overview-page.view-model";

/**
 * Test della View (Component Angular): copre solo il collante col template
 * — costruzione del ViewModel e rendering dei suoi segnali. La logica
 * (conteggi, navigazione) è testata senza Angular in
 * overview-page.view-model.spec.ts.
 */
describe("OverviewPage", () => {
  const state = signal<MvpState | null>(null);
  const loading = signal(false);
  const error = signal<string | null>(null);
  const history = signal<MvpState["assistant"]["history"]>([]);
  let navigate: jest.Mock;
  let metricEntry: jest.Mock;

  const entries: Record<string, Metric> = {
    "assistant.drafts": { key: "assistant.drafts", value: 8, label: "Bozze generate" },
    "copilot.needs_review": { key: "copilot.needs_review", value: 3, label: "Da verificare" },
    "copilot.quarantined": { key: "copilot.quarantined", value: 0, label: "In quarantena" },
    "copilot.sub_documents": { key: "copilot.sub_documents", value: 412, label: "Sotto-documenti" },
    "assistant.rating_average": { key: "assistant.rating_average", value: "4.3", label: "Media stelle" },
    "copilot.ocr_confidence": { key: "copilot.ocr_confidence", value: "92.4", unit: "%", threshold: 80, label: "Confidenza media OCR" }
  };

  beforeEach(() => {
    navigate = jest.fn(() => Promise.resolve(true));
    metricEntry = jest.fn((key: string) => entries[key] ?? null);
    TestBed.configureTestingModule({
      providers: [
        { provide: Router, useValue: { navigate } },
        { provide: MvpStateStore, useValue: { state, loading, error, history, metricEntry } }
      ]
    });
  });

  it("costruisce il ViewModel e delega ad esso i conteggi e la navigazione", () => {
    const fixture = TestBed.createComponent(OverviewPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["vm"]).toBeInstanceOf(OverviewPageViewModel);
    expect(fixture.componentInstance["vm"].priorities()[0]?.value).toBe(3);
  });

  it("segnala la quarantena solo quando ce n'è davvero", () => {
    const fixture = TestBed.createComponent(OverviewPage);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain("in quarantena");

    entries["copilot.quarantined"] = { key: "copilot.quarantined", value: 4, label: "In quarantena" };
    const withQuarantine = TestBed.createComponent(OverviewPage);
    withQuarantine.detectChanges();

    expect((withQuarantine.nativeElement as HTMLElement).textContent).toContain(
      "4 sotto-documenti in quarantena"
    );
    entries["copilot.quarantined"] = { key: "copilot.quarantined", value: 0, label: "In quarantena" };
  });

  it("rende errore, storico e stato vuoto in base ai signal", () => {
    error.set("Backend non disponibile");
    history.set([{ id: 1, title: "Avviso", status: "draft", createdAt: "2026-07-31" }] as MvpState["assistant"]["history"]);
    const fixture = TestBed.createComponent(OverviewPage);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain("Backend non disponibile");
    expect((fixture.nativeElement as HTMLElement).textContent).toContain("Avviso");

    error.set(null);
    history.set([]);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain("Le nuove attività compariranno qui.");
  });
});
