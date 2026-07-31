import { Injector } from "@angular/core";
import { AlittlebyteMVPAPIService } from "../../../api/generated/mvp-api";
import { MvpStateStore } from "./mvp-state.store";
import type { MvpState } from "../../../api/generated/model";

function stateWith(assistantMetrics: MvpState["assistant"]["metrics"], copilotMetrics: MvpState["copilot"]["metrics"]): MvpState {
  return {
    assistant: { metrics: assistantMetrics, history: [] },
    copilot: { metrics: copilotMetrics, documents: [] }
  } as MvpState;
}

describe("MvpStateStore.metric", () => {
  let store: MvpStateStore;

  beforeEach(() => {
    const injector = Injector.create({
      providers: [
        { provide: AlittlebyteMVPAPIService, useValue: {} },
        { provide: MvpStateStore, useClass: MvpStateStore, deps: [] }
      ]
    });

    store = injector.get(MvpStateStore);
  });

  it("seleziona per chiave stabile e non per label", () => {
    store.setState(
      stateWith(
        [{ key: "assistant.drafts", value: 12, label: "Bozze generate" }],
        [{ key: "copilot.validated", value: 5, label: "Documenti pronti" }]
      )
    );

    expect(store.metric("assistant.drafts")).toBe(12);
    expect(store.metric("copilot.validated")).toBe(5);
  });

  it("ritorna 0 per una chiave assente, senza sollevare", () => {
    store.setState(stateWith([], []));

    expect(store.metric("copilot.inesistente")).toBe(0);
  });

  it("ritorna 0 quando il valore non e' numerico, come la media stelle vuota", () => {
    store.setState(stateWith([{ key: "assistant.rating_average", value: "—", label: "Media stelle" }], []));

    expect(store.metric("assistant.rating_average")).toBe(0);
  });
});
