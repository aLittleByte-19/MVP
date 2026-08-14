import { TestBed } from "@angular/core/testing";
import { Router } from "@angular/router";
import { signal } from "@angular/core";
import type { MvpState } from "../../../api/generated/model";
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
  let metric: jest.Mock;

  beforeEach(() => {
    navigate = jest.fn(() => Promise.resolve(true));
    metric = jest.fn(
      (key: string) =>
        ({
          "assistant.drafts": 8,
          "copilot.needs_review": 3,
          "copilot.validated": 5
        })[key] ?? 0
    );
    TestBed.configureTestingModule({
      providers: [
        { provide: Router, useValue: { navigate } },
        { provide: MvpStateStore, useValue: { state, loading, error, history, metric } }
      ]
    });
  });

  it("costruisce il ViewModel e delega ad esso i conteggi e la navigazione", () => {
    const fixture = TestBed.createComponent(OverviewPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["vm"]).toBeInstanceOf(OverviewPageViewModel);
    expect(fixture.componentInstance["vm"].generatedDrafts()).toBe(8);
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
