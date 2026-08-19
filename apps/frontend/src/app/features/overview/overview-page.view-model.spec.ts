import type { Router } from "@angular/router";
import type { Metric } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { OverviewPageViewModel } from "./overview-page.view-model";

/**
 * Test di dominio puro sul ViewModel (nessun bootstrap Angular/TestBed):
 * OverviewPageViewModel si costruisce con `new`, come qualunque classe
 * TypeScript — prova diretta che qui MVVM non è solo nominale.
 */
describe("OverviewPageViewModel", () => {
  let navigate: jest.Mock;
  let metricEntry: jest.Mock;

  const entries: Record<string, Metric> = {
    "assistant.drafts": { key: "assistant.drafts", value: 8, label: "Bozze generate" },
    "copilot.needs_review": {
      key: "copilot.needs_review",
      value: 3,
      label: "Da verificare",
      history: [0, 1, 0, 0, 2, 0, 1]
    },
    "copilot.quarantined": { key: "copilot.quarantined", value: 2, label: "In quarantena" },
    "copilot.sub_documents": { key: "copilot.sub_documents", value: 412, label: "Sotto-documenti" },
    "assistant.rating_average": {
      key: "assistant.rating_average",
      value: "4.3",
      unit: "/ 5",
      label: "Media stelle"
    },
    "copilot.confident_fields": {
      key: "copilot.confident_fields",
      value: 1284,
      label: "Campi con confidenza"
    }
  };

  beforeEach(() => {
    navigate = jest.fn(() => Promise.resolve(true));
    metricEntry = jest.fn((key: string) => entries[key] ?? null);
  });

  function createViewModel(
    scrollTo: jest.Mock = jest.fn(),
    lookup: jest.Mock = metricEntry
  ): OverviewPageViewModel {
    const store = {
      metricEntry: lookup,
      history: () => [],
      error: () => null,
      loading: () => false
    } as unknown as MvpStateStore;
    const router = { navigate } as unknown as Router;
    return new OverviewPageViewModel(store, router, scrollTo);
  }

  it("espone solo le tre metriche su cui si agisce", () => {
    // I conteggi descrittivi vivono nelle pagine dei moduli: qui comparivano
    // due volte, come priorità e dentro i pannelli sottostanti.
    const vm = createViewModel();

    expect(vm.priorities().map((priority) => priority.key)).toEqual([
      "copilot.needs_review",
      "copilot.quarantined",
      "assistant.drafts"
    ]);
  });

  it("lascia il valore a null finché lo stato non è caricato", () => {
    // Uno zero durante il caricamento verrebbe letto come conteggio reale.
    const vm = createViewModel(jest.fn(), jest.fn(() => null));

    expect(vm.priorities().every((priority) => priority.value === null)).toBe(true);
    expect(vm.priorities().every((priority) => priority.context === null)).toBe(true);
    expect(vm.priorities().every((priority) => priority.outOf === null)).toBe(true);
  });

  it("espone la quota sul totale e gli ingressi di oggi", () => {
    const vm = createViewModel();
    const review = vm.priorities()[0]!;

    expect(review.value).toBe(3);
    // Il totale sta accanto al valore ("3/412"), non nella riga di contesto.
    expect(review.outOf).toBe(412);
    expect(review.context).toBe("1 nuovo oggi");
    expect(review.tone).toBe("watch");
  });

  it("formatta gli indicatori di qualità dei due moduli", () => {
    const vm = createViewModel();

    expect(vm.assistantQuality()).toEqual({ label: "Media stelle", value: "4,3", unit: "/ 5" });
    expect(vm.copilotQuality()).toEqual({
      label: "Campi con confidenza",
      value: "1.284",
      unit: null
    });
  });

  it("conta i sotto-documenti in quarantena, per decidere se segnalarli", () => {
    expect(createViewModel().quarantined()).toBe(2);
    expect(createViewModel(jest.fn(), jest.fn(() => null)).quarantined()).toBe(0);
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
