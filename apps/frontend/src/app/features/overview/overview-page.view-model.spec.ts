import type { Router } from "@angular/router";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { OverviewPageViewModel } from "./overview-page.view-model";

/**
 * Test di dominio puro sul ViewModel (nessun bootstrap Angular/TestBed):
 * OverviewPageViewModel si costruisce con `new`, come qualunque classe
 * TypeScript — prova diretta che qui MVVM non è solo nominale.
 */
describe("OverviewPageViewModel", () => {
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
  });

  function createViewModel(scrollTo: jest.Mock = jest.fn()): OverviewPageViewModel {
    const store = { metric, history: () => [] } as unknown as MvpStateStore;
    const router = { navigate } as unknown as Router;
    return new OverviewPageViewModel(store, router, scrollTo);
  }

  it("legge i conteggi autorevoli dallo store", () => {
    const vm = createViewModel();

    expect(vm.generatedDrafts()).toBe(8);
    expect(vm.documentsToReview()).toBe(3);
    expect(vm.readyDocuments()).toBe(5);
    expect(metric.mock.calls.map(([key]) => key)).toEqual([
      "assistant.drafts",
      "copilot.needs_review",
      "copilot.validated"
    ]);
  });

  it("naviga e delega lo scroll alla View dopo la navigazione", async () => {
    const scrollTo = jest.fn();
    const vm = createViewModel(scrollTo);

    vm.navigate("assistant", "assistant-compose");
    await Promise.resolve();

    expect(navigate).toHaveBeenCalledWith(["assistant"]);
    expect(scrollTo).toHaveBeenCalledWith("assistant-compose");
  });
});
