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

  function createViewModel(): OverviewPageViewModel {
    const store = { metric, history: () => [] } as unknown as MvpStateStore;
    const router = { navigate } as unknown as Router;
    return new OverviewPageViewModel(store, router);
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

  it("naviga e scorre alla sezione richiesta", async () => {
    const vm = createViewModel();
    const target = document.createElement("div");
    target.id = "assistant-compose";
    target.scrollIntoView = jest.fn();
    document.body.appendChild(target);
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });

    vm.navigate("assistant", "assistant-compose");
    await Promise.resolve();

    expect(navigate).toHaveBeenCalledWith(["assistant"]);
    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
    animation.mockRestore();
    target.remove();
  });
});
