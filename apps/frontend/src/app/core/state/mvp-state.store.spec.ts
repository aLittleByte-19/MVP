import { Injector } from "@angular/core";
import { defer, of, throwError } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../api/generated/mvp-api";
import { MvpStateStore } from "./mvp-state.store";
import type { MvpState, SubDocument } from "../../../api/generated/model";

function stateWith(assistantMetrics: MvpState["assistant"]["metrics"], copilotMetrics: MvpState["copilot"]["metrics"]): MvpState {
  return {
    assistant: { metrics: assistantMetrics, history: [], promptConfigurations: [] },
    copilot: { metrics: copilotMetrics, documents: [] }
  } as MvpState;
}

describe("MvpStateStore", () => {
  let store: MvpStateStore;
  let getMvpState: jest.Mock;

  beforeEach(() => {
    getMvpState = jest.fn();
    const injector = Injector.create({
      providers: [
        { provide: AlittlebyteMVPAPIService, useValue: { getMvpState } },
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

  it("espone collezioni vuote prima del caricamento", () => {
    expect(store.state()).toBeNull();
    expect(store.documents()).toEqual([]);
    expect(store.history()).toEqual([]);
    expect(store.promptConfigurations()).toEqual([]);
    expect(store.assistantMetrics()).toEqual([]);
    expect(store.copilotMetrics()).toEqual([]);
  });

  it("espone le configurazioni di prompt salvate (UC-19)", () => {
    const state = stateWith([], []);
    state.assistant.promptConfigurations = [
      { id: 1, name: "Ferie estive", prompt: "Un prompt qualsiasi", tone: "Empatico", style: "Comunicato" }
    ];
    store.setState(state);

    expect(store.promptConfigurations()).toEqual(state.assistant.promptConfigurations);
  });

  it("carica lo stato una sola volta e aggiorna loading ed errore", () => {
    const state = stateWith([{ key: "assistant.drafts", value: 3, label: "Bozze" }], []);
    getMvpState.mockReturnValue(of(state));

    store.loadOnce();
    store.loadOnce();

    expect(getMvpState).toHaveBeenCalledTimes(1);
    expect(store.state()).toBe(state);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });

  it("ritenta una volta un errore temporaneo prima di salvare lo stato", () => {
    const state = stateWith([], []);
    let subscriptions = 0;
    getMvpState.mockReturnValue(defer(() => {
      subscriptions += 1;
      return subscriptions === 1 ? throwError(() => new Error("temporaneo")) : of(state);
    }));

    store.reload();

    expect(store.state()).toBe(state);
    expect(subscriptions).toBe(2);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });

  it("espone un messaggio e termina il caricamento dopo due errori", () => {
    getMvpState.mockReturnValue(throwError(() => new Error("servizio non disponibile")));

    store.reload();

    expect(store.state()).toBeNull();
    expect(store.loading()).toBe(false);
    expect(store.error()).toBe("servizio non disponibile");
  });

  it("ritenta loadOnce dopo un primo caricamento fallito", () => {
    getMvpState.mockReturnValue(throwError(() => new Error("servizio non disponibile")));
    store.loadOnce();
    expect(store.error()).toBe("servizio non disponibile");
    expect(store.state()).toBeNull();

    const state = stateWith([], []);
    getMvpState.mockReturnValue(of(state));
    store.loadOnce();

    expect(store.state()).toBe(state);
    expect(store.error()).toBeNull();
  });

  it("ignora un aggiornamento documento finche' lo stato non e' disponibile", () => {
    store.upsertDocument({ id: 1 } as unknown as SubDocument);

    expect(store.state()).toBeNull();
  });

  it("inserisce un documento in testa e sostituisce quello con lo stesso id", () => {
    const first = { id: 1, name: "Prima versione" } as unknown as SubDocument;
    const other = { id: 2, name: "Altro" } as unknown as SubDocument;
    const updated = { id: 1, name: "Versione aggiornata" } as unknown as SubDocument;
    const state = stateWith([], []);
    state.copilot.documents = [first, other];
    store.setState(state);

    store.upsertDocument(updated);

    expect(store.documents()).toEqual([updated, other]);
    expect(store.state()?.assistant).toBe(state.assistant);
  });
});
