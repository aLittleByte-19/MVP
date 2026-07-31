import { TestBed } from "@angular/core/testing";
import { Router } from "@angular/router";
import { signal } from "@angular/core";
import type { MvpState } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { OverviewPage } from "./overview-page";

describe("OverviewPage", () => {
  const state = signal<MvpState | null>(null);
  const loading = signal(false);
  const error = signal<string | null>(null);
  const history = signal<MvpState["assistant"]["history"]>([]);
  let navigate: jest.Mock;
  let metric: jest.Mock;

  beforeEach(() => {
    navigate = jest.fn(() => Promise.resolve(true));
    metric = jest.fn((key: string) => ({
      "assistant.drafts": 8,
      "copilot.needs_review": 3,
      "copilot.validated": 5
    })[key] ?? 0);
    TestBed.configureTestingModule({
      providers: [
        { provide: Router, useValue: { navigate } },
        { provide: MvpStateStore, useValue: { state, loading, error, history, metric } }
      ]
    });
  });

  it("legge i conteggi autorevoli dallo store", () => {
    const fixture = TestBed.createComponent(OverviewPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["generatedDrafts"]()).toBe(8);
    expect(fixture.componentInstance["documentsToReview"]()).toBe(3);
    expect(fixture.componentInstance["readyDocuments"]()).toBe(5);
    expect(metric.mock.calls.map(([key]) => key)).toEqual([
      "assistant.drafts",
      "copilot.needs_review",
      "copilot.validated"
    ]);
  });

  it("naviga e scorre alla sezione richiesta", async () => {
    const fixture = TestBed.createComponent(OverviewPage);
    const target = document.createElement("div");
    target.id = "assistant-compose";
    target.scrollIntoView = jest.fn();
    document.body.appendChild(target);
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });

    fixture.componentInstance["navigate"]("assistant", "assistant-compose");
    await Promise.resolve();

    expect(navigate).toHaveBeenCalledWith(["assistant"]);
    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
    animation.mockRestore();
    target.remove();
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
    expect((fixture.nativeElement as HTMLElement).textContent).toContain("Le nuove attivita compariranno qui.");
  });
});
