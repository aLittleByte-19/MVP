import { signal } from "@angular/core";
import { of, throwError } from "rxjs";
import type { Communication, PromptConfiguration } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { AssistantPageViewModel } from "./assistant-page.view-model";
import type { CommunicationDraftForm, GeneratedDraft } from "./assistant.model";
import type { AssistantService } from "./data/assistant.service";

function communication(overrides: Partial<Communication> = {}): Communication {
  return {
    id: 7,
    prompt: "Avvisa il personale della nuova area documentale",
    tone: "Chiaro e diretto",
    style: "Testo informativo",
    title: "Nuova area documentale",
    body: "La nuova area e disponibile.",
    previewUrl: "/communications/7/preview",
    exportUrl: "/communications/7/export",
    coverImageUrl: null,
    coverStatus: "removed",
    coverStatusLabel: "Nessuna copertina",
    generationStatus: "completed",
    generationStatusLabel: "Completata",
    status: "Bozza",
    statusValue: "draft",
    isFavorite: false,
    rating: null,
    ratingComment: null,
    ratedAt: null,
    ...overrides
  };
}

/**
 * Test di dominio puro sul ViewModel (nessun bootstrap Angular/TestBed):
 * AssistantPageViewModel si costruisce con `new`, come qualunque classe
 * TypeScript — prova diretta che qui MVVM non è solo nominale.
 */
describe("AssistantPageViewModel", () => {
  const draftForm: CommunicationDraftForm = {
    prompt: "Avvisa il personale della nuova area documentale",
    tone: "Chiaro e diretto",
    style: "Testo informativo"
  };
  let history: ReturnType<typeof signal<Communication[]>>;
  let promptConfigurations: ReturnType<typeof signal<PromptConfiguration[]>>;
  let error: ReturnType<typeof signal<string | null>>;
  let assistant: Record<string, jest.Mock>;
  let scrollTo: jest.Mock;

  beforeEach(() => {
    history = signal<Communication[]>([]);
    promptConfigurations = signal<PromptConfiguration[]>([]);
    error = signal<string | null>(null);
    assistant = {
      searchCommunications: jest.fn(() => of([])),
      generate: jest.fn(),
      regenerate: jest.fn(),
      rate: jest.fn(),
      updateCoverImage: jest.fn(),
      update: jest.fn(),
      removeCoverImage: jest.fn(),
      discard: jest.fn(),
      saveToHistory: jest.fn(),
      saveConfiguration: jest.fn(),
      deleteConfiguration: jest.fn(),
      deleteFromHistory: jest.fn(),
      favorite: jest.fn(),
      unfavorite: jest.fn()
    };
    scrollTo = jest.fn();
  });

  function createViewModel(): AssistantPageViewModel {
    const store = { history, promptConfigurations, error } as unknown as MvpStateStore;
    return new AssistantPageViewModel(assistant as unknown as AssistantService, store, scrollTo);
  }

  function setDraft(vm: AssistantPageViewModel, overrides: Partial<GeneratedDraft> = {}): GeneratedDraft {
    const draft: GeneratedDraft = {
      id: 7,
      title: "Titolo",
      body: "Corpo",
      status: "Bozza",
      statusValue: "draft",
      coverStatus: "removed",
      rating: null,
      ...overrides
    };
    vm.latestDraft.set(draft);
    return draft;
  }

  it("espone error come pass-through dello store, senza che la View lo legga direttamente", () => {
    const vm = createViewModel();

    expect(vm.error()).toBeNull();

    error.set("errore autorevole");
    expect(vm.error()).toBe("errore autorevole");
  });

  it("reload cerca con i filtri attivi, imposta i risultati e azzera l'errore dello storico", () => {
    const record = communication();
    assistant["searchCommunications"].mockReturnValue(of([record]));
    const vm = createViewModel();
    vm.setActiveFilters({ keyword: "ferie" });

    vm.reload();

    expect(assistant["searchCommunications"]).toHaveBeenCalledWith({ keyword: "ferie" });
    expect(vm.filteredCommunications()).toEqual([record]);
    expect(vm.historyError()).toBeNull();
  });

  it("seleziona la sorgente corretta per l'anteprima", () => {
    const record = communication();
    history.set([record]);
    const vm = createViewModel();

    expect(vm.previewDraft()).toBeNull();

    const latest = setDraft(vm, { id: 9 });
    expect(vm.previewDraft()).toBe(latest);

    vm.selectedDraftId.set(7);
    expect(vm.previewDraft()).toMatchObject({ id: 7, title: "Nuova area documentale", rating: null });

    vm.selectedDraftId.set(404);
    expect(vm.previewDraft()).toBe(latest);
  });

  it("reload segnala un errore nel caricamento dello storico", () => {
    assistant["searchCommunications"].mockReturnValue(throwError(() => new Error("storico offline")));
    const vm = createViewModel();

    vm.reload();

    expect(vm.historyError()).toBe("storico offline");
  });

  it("genera una bozza incrementale da testo, copertina e risposta finale", () => {
    const completed = communication({ coverImageUrl: "/cover.png", coverStatus: "ready" });
    assistant["generate"].mockReturnValue(
      of(
        { status: "Testo pronto", phase: "generating-cover", communicationId: 7, text: { title: null, body: "Corpo" } },
        {
          status: "Cover pronta",
          phase: "generating-cover",
          communicationId: 7,
          cover: { coverImageUrl: "/cover.png", coverStatus: "ready", coverError: null }
        },
        { status: "Completata", phase: "completed", communicationId: 7, communication: completed }
      )
    );
    const vm = createViewModel();

    vm.generate(draftForm);

    expect(assistant["generate"]).toHaveBeenCalledWith(draftForm);
    expect(vm.phase()).toBe("completed");
    expect(vm.status()).toBe("Completata");
    expect(vm.previewDraft()).toMatchObject({ id: 7, title: "Nuova area documentale", coverImageUrl: "/cover.png" });
    expect(vm.isGenerating()).toBe(false);
  });

  it("gestisce la copertina arrivata prima del testo con valori di fallback", () => {
    assistant["generate"].mockReturnValue(
      of({
        status: "Cover non disponibile",
        phase: "generating-cover",
        communicationId: 7,
        cover: { coverImageUrl: null, coverStatus: "failed", coverError: "errore cover" }
      })
    );
    const vm = createViewModel();

    vm.generate(draftForm);

    expect(vm.previewDraft()).toMatchObject({
      id: 7,
      title: "",
      body: "",
      status: "draft",
      coverStatus: "failed",
      coverError: "errore cover"
    });
    expect(vm.isGenerating()).toBe(true);
  });

  it("espone l'errore della richiesta di generazione", () => {
    assistant["generate"].mockReturnValue(throwError(() => new Error("AI offline")));
    const vm = createViewModel();

    vm.generate(draftForm);

    expect(vm.phase()).toBe("failed");
    expect(vm.status()).toBe("AI offline");
  });

  it("rigenera solo quando esiste una bozza e gestisce successo ed errore", () => {
    const vm = createViewModel();
    vm.regenerate();
    expect(assistant["regenerate"]).not.toHaveBeenCalled();

    setDraft(vm);
    assistant["regenerate"].mockReturnValue(of({ status: "Rigenerata", phase: "completed", communicationId: 7 }));
    vm.regenerate();
    expect(assistant["regenerate"]).toHaveBeenCalledWith(7);
    expect(vm.status()).toBe("Rigenerata");

    assistant["regenerate"].mockReturnValue(throwError(() => new Error("rigenerazione fallita")));
    vm.regenerate();
    expect(vm.phase()).toBe("failed");
    expect(vm.status()).toBe("rigenerazione fallita");
  });

  it("valuta una bozza una sola volta e aggiorna selezione e stato", () => {
    const vm = createViewModel();
    vm.rateDraft({ rating: 5 });
    expect(assistant["rate"]).not.toHaveBeenCalled();

    setDraft(vm);
    assistant["rate"].mockReturnValue(of({ message: "Valutazione salvata", communication: communication({ rating: 5 }) }));
    vm.rateDraft({ rating: 5, comment: "Ottima" });

    expect(assistant["rate"]).toHaveBeenCalledWith(7, { rating: 5, comment: "Ottima" });
    expect(vm.previewDraft()?.rating).toBe(5);
    expect(vm.selectedDraftId()).toBe(7);
    expect(vm.isRating()).toBe(false);

    vm.rateDraft({ rating: 4 });
    expect(assistant["rate"]).toHaveBeenCalledTimes(1);
  });

  it("espone l'errore di valutazione e conclude il caricamento", () => {
    const vm = createViewModel();
    setDraft(vm);
    assistant["rate"].mockReturnValue(throwError(() => new Error("rating fallito")));

    vm.rateDraft({ rating: 2 });

    expect(vm.rateError()).toBe("rating fallito");
    expect(vm.isRating()).toBe(false);
  });

  it("seleziona una bozza e azzera l'errore rating", () => {
    const vm = createViewModel();
    vm.rateError.set("errore precedente");

    vm.selectDraft(12);

    expect(vm.selectedDraftId()).toBe(12);
    expect(vm.rateError()).toBeNull();
    expect(scrollTo).toHaveBeenCalledWith("assistant-review");
  });

  it("carica e rimuove la copertina aggiornando sempre lo stato busy", () => {
    const vm = createViewModel();
    const file = new File(["cover"], "cover.png", { type: "image/png" });
    vm.uploadCover(file);
    vm.removeCover();
    expect(assistant["updateCoverImage"]).not.toHaveBeenCalled();
    expect(assistant["removeCoverImage"]).not.toHaveBeenCalled();

    setDraft(vm);
    assistant["updateCoverImage"].mockReturnValue(
      of({ message: "Cover aggiornata", communication: communication({ coverStatus: "ready" }) })
    );
    assistant["removeCoverImage"].mockReturnValue(of({ message: "Cover rimossa", communication: communication() }));

    vm.uploadCover(file);
    expect(assistant["updateCoverImage"]).toHaveBeenCalledWith(7, file);
    expect(vm.status()).toBe("Cover aggiornata");
    expect(vm.isUpdatingCover()).toBe(false);

    vm.removeCover();
    expect(assistant["removeCoverImage"]).toHaveBeenCalledWith(7);
    expect(vm.status()).toBe("Cover rimossa");
    expect(vm.isUpdatingCover()).toBe(false);
  });

  it("gestisce gli errori di caricamento e rimozione copertina", () => {
    const vm = createViewModel();
    setDraft(vm);
    assistant["updateCoverImage"].mockReturnValue(throwError(() => new Error("upload fallito")));
    assistant["removeCoverImage"].mockReturnValue(throwError(() => new Error("rimozione fallita")));

    vm.uploadCover(new File(["x"], "x.png"));
    expect(vm.status()).toBe("upload fallito");
    expect(vm.isUpdatingCover()).toBe(false);

    vm.removeCover();
    expect(vm.status()).toBe("rimozione fallita");
    expect(vm.isUpdatingCover()).toBe(false);
  });

  it("salva le modifiche oppure espone l'errore", () => {
    const vm = createViewModel();
    assistant["update"].mockReturnValue(of({ communication: communication({ title: "Titolo aggiornato" }) }));

    vm.saveDraft({ communicationId: 7, payload: { title: "Titolo aggiornato", body: "Corpo" } });

    expect(vm.previewDraft()?.title).toBe("Titolo aggiornato");
    expect(vm.selectedDraftId()).toBe(7);
    expect(vm.isSavingDraft()).toBe(false);

    assistant["update"].mockReturnValue(throwError(() => new Error("salvataggio fallito")));
    vm.saveDraft({ communicationId: 7, payload: { title: "X", body: "Y" } });
    expect(vm.saveDraftError()).toBe("salvataggio fallito");
    expect(vm.isSavingDraft()).toBe(false);
  });

  it("scarta una bozza o mantiene l'anteprima in caso di errore", () => {
    const vm = createViewModel();
    vm.discard();
    expect(assistant["discard"]).not.toHaveBeenCalled();

    setDraft(vm);
    assistant["discard"].mockReturnValue(of({ message: "Bozza eliminata" }));
    vm.discard();
    expect(vm.previewDraft()).toBeNull();
    expect(vm.status()).toBe("Bozza eliminata");
    expect(vm.isDiscarding()).toBe(false);

    setDraft(vm);
    assistant["discard"].mockReturnValue(throwError(() => new Error("scarto fallito")));
    vm.discard();
    expect(vm.status()).toBe("scarto fallito");
    expect(vm.previewDraft()).not.toBeNull();
  });

  it("salva una bozza nello storico (UC-9) o mantiene lo stato in caso di errore", () => {
    const vm = createViewModel();
    vm.saveToHistory();
    expect(assistant["saveToHistory"]).not.toHaveBeenCalled();

    setDraft(vm);
    assistant["saveToHistory"].mockReturnValue(
      of({ message: "Bozza salvata nello storico.", communication: communication({ status: "Approvata", statusValue: "approved" }) })
    );
    vm.saveToHistory();
    expect(vm.status()).toBe("Bozza salvata nello storico.");
    expect(vm.previewDraft()?.status).toBe("Approvata");
    expect(vm.isSavingToHistory()).toBe(false);

    setDraft(vm);
    assistant["saveToHistory"].mockReturnValue(throwError(() => new Error("salvataggio fallito")));
    vm.saveToHistory();
    expect(vm.status()).toBe("salvataggio fallito");
  });

  it("salva la configurazione del prompt o segnala l'errore (UC-19)", () => {
    const vm = createViewModel();
    assistant["saveConfiguration"].mockReturnValue(of({ message: "Configurazione salvata." }));

    vm.saveConfiguration({ prompt: "Un prompt qualsiasi lungo abbastanza", tone: "Tecnico", style: "Avviso operativo" });

    expect(vm.status()).toBe("Configurazione salvata.");
    expect(vm.isSavingConfiguration()).toBe(false);

    assistant["saveConfiguration"].mockReturnValue(throwError(() => new Error("salvataggio config fallito")));
    vm.saveConfiguration({ prompt: "Un prompt qualsiasi lungo abbastanza", tone: "Tecnico", style: "Avviso operativo" });

    expect(vm.saveConfigurationError()).toBe("salvataggio config fallito");
  });

  it("filtra le configurazioni salvate con gli stessi filtri dello storico", () => {
    promptConfigurations.set([
      { id: 1, name: "Ferie estive", prompt: "Avviso sulle ferie estive", tone: "Empatico", style: "Avviso operativo", createdAt: "2026-03-04" },
      {
        id: 2,
        name: "Manutenzione",
        prompt: "Comunicazione tecnica di manutenzione",
        tone: "Tecnico",
        style: "Testo informativo",
        createdAt: "2026-05-01"
      }
    ]);
    const vm = createViewModel();

    expect(vm.filteredPromptConfigurations()).toHaveLength(2);

    vm.setActiveFilters({ keyword: "ferie" });
    expect(vm.filteredPromptConfigurations().map((c) => c.id)).toEqual([1]);

    vm.setActiveFilters({ tone: "Tecnico" });
    expect(vm.filteredPromptConfigurations().map((c) => c.id)).toEqual([2]);

    vm.setActiveFilters({ style: "Avviso operativo" });
    expect(vm.filteredPromptConfigurations().map((c) => c.id)).toEqual([1]);

    vm.setActiveFilters({ date: "2026-05-01" });
    expect(vm.filteredPromptConfigurations().map((c) => c.id)).toEqual([2]);

    vm.setActiveFilters({ keyword: "nessuna corrispondenza" });
    expect(vm.filteredPromptConfigurations()).toEqual([]);
  });

  it("riflette la presenza di filtri attivi", () => {
    const vm = createViewModel();

    expect(vm.hasActiveFilters()).toBe(false);
    vm.setActiveFilters({ keyword: "ferie" });
    expect(vm.hasActiveFilters()).toBe(true);
  });

  it("riusa una configurazione salvata senza chiamare il backend (UC-19)", () => {
    const vm = createViewModel();

    vm.useConfiguration({
      id: 1,
      name: "Ferie estive",
      prompt: "Prompt della configurazione salvata",
      tone: "Empatico",
      style: "Avviso operativo"
    });

    expect(vm.prefillPayload()).toEqual({
      prompt: "Prompt della configurazione salvata",
      tone: "Empatico",
      style: "Avviso operativo"
    });
    expect(assistant["generate"]).not.toHaveBeenCalled();
  });

  it("elimina una configurazione salvata o segnala l'errore", () => {
    const vm = createViewModel();
    vm.confirmingConfigDeleteId.set(3);
    assistant["deleteConfiguration"].mockReturnValue(of({ message: "Configurazione eliminata." }));

    vm.deleteConfiguration(3);

    expect(vm.status()).toBe("Configurazione eliminata.");
    expect(vm.confirmingConfigDeleteId()).toBeNull();
    expect(vm.isDeletingConfig()).toBe(false);

    assistant["deleteConfiguration"].mockReturnValue(throwError(() => new Error("eliminazione config fallita")));
    vm.deleteConfiguration(3);

    expect(vm.status()).toBe("eliminazione config fallita");
  });

  it("elimina dallo storico e pulisce le anteprime collegate", () => {
    const vm = createViewModel();
    setDraft(vm);
    vm.selectedDraftId.set(7);
    vm.confirmingDeleteId.set(7);
    assistant["deleteFromHistory"].mockReturnValue(of({ message: "Eliminata" }));

    vm.deleteHistoryItem(7);

    expect(vm.selectedDraftId()).toBeNull();
    expect(vm.latestDraft()).toBeNull();
    expect(vm.confirmingDeleteId()).toBeNull();
    expect(vm.isDeletingHistoryItem()).toBe(false);
  });

  it("non pulisce una anteprima diversa e gestisce l'errore di eliminazione", () => {
    const vm = createViewModel();
    setDraft(vm, { id: 9 });
    vm.selectedDraftId.set(9);
    assistant["deleteFromHistory"].mockReturnValue(throwError(() => new Error("delete fallita")));

    vm.deleteHistoryItem(7);

    expect(vm.latestDraft()?.id).toBe(9);
    expect(vm.selectedDraftId()).toBe(9);
    expect(vm.status()).toBe("delete fallita");
    expect(vm.isDeletingHistoryItem()).toBe(false);
  });

  it("aggiunge e rimuove una comunicazione dai preferiti", () => {
    const vm = createViewModel();
    const notFavorite = communication({ id: 7, isFavorite: false });

    assistant["favorite"].mockReturnValue(of({ message: "Generazione aggiunta ai preferiti." }));
    vm.toggleFavorite(notFavorite);

    expect(assistant["favorite"]).toHaveBeenCalledWith(7);
    expect(assistant["unfavorite"]).not.toHaveBeenCalled();
    expect(vm.status()).toBe("Generazione aggiunta ai preferiti.");
    expect(vm.togglingFavoriteId()).toBeNull();

    const favorite = communication({ id: 7, isFavorite: true });
    assistant["unfavorite"].mockReturnValue(of({ message: "Generazione rimossa dai preferiti." }));
    vm.toggleFavorite(favorite);

    expect(assistant["unfavorite"]).toHaveBeenCalledWith(7);
    expect(vm.status()).toBe("Generazione rimossa dai preferiti.");
    expect(vm.togglingFavoriteId()).toBeNull();
  });

  it("espone l'errore di aggiornamento dei preferiti", () => {
    const vm = createViewModel();
    assistant["favorite"].mockReturnValue(throwError(() => new Error("preferiti non disponibili")));

    vm.toggleFavorite(communication({ id: 7, isFavorite: false }));

    expect(vm.status()).toBe("preferiti non disponibili");
    expect(vm.togglingFavoriteId()).toBeNull();
  });
});
