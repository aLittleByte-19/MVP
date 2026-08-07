import { signal } from "@angular/core";
import { TestBed } from "@angular/core/testing";
import { of, throwError } from "rxjs";
import type { Communication, PromptConfiguration } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import type { CommunicationDraftForm, GeneratedDraft } from "./assistant.model";
import { AssistantPage } from "./assistant-page";
import { AssistantService } from "./data/assistant.service";

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

describe("AssistantPage", () => {
  const draftForm: CommunicationDraftForm = {
    prompt: "Avvisa il personale della nuova area documentale",
    tone: "Chiaro e diretto",
    style: "Testo informativo"
  };
  let history: ReturnType<typeof signal<Communication[]>>;
  let promptConfigurations: ReturnType<typeof signal<PromptConfiguration[]>>;
  let assistant: Record<string, jest.Mock>;
  let animation: jest.SpyInstance;

  beforeEach(() => {
    history = signal<Communication[]>([]);
    promptConfigurations = signal<PromptConfiguration[]>([]);
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
    animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MvpStateStore,
          useValue: { history, error: signal(null), promptConfigurations }
        },
        { provide: AssistantService, useValue: assistant }
      ]
    });
    TestBed.overrideComponent(AssistantPage, { set: { template: "", imports: [] } });
  });

  afterEach(() => animation.mockRestore());

  function createPage(): AssistantPage {
    const fixture = TestBed.createComponent(AssistantPage);
    fixture.detectChanges();
    return fixture.componentInstance;
  }

  function setDraft(page: AssistantPage, overrides: Partial<GeneratedDraft> = {}): GeneratedDraft {
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
    page["latestDraft"].set(draft);
    return draft;
  }

  it("carica lo storico e seleziona la sorgente corretta per l'anteprima", () => {
    const record = communication();
    history.set([record]);
    assistant["searchCommunications"].mockReturnValue(of([record]));
    const page = createPage();

    expect(page["filteredCommunications"]()).toEqual([record]);
    expect(page["historyError"]()).toBeNull();
    expect(page["previewDraft"]()).toBeNull();

    const latest = setDraft(page, { id: 9 });
    expect(page["previewDraft"]()).toBe(latest);

    page["selectedDraftId"].set(7);
    expect(page["previewDraft"]()).toMatchObject({ id: 7, title: "Nuova area documentale", rating: null });

    page["selectedDraftId"].set(404);
    expect(page["previewDraft"]()).toBe(latest);
  });

  it("segnala un errore nel caricamento dello storico", () => {
    assistant["searchCommunications"].mockReturnValue(throwError(() => new Error("storico offline")));

    const page = createPage();

    expect(page["historyError"]()).toBe("storico offline");
  });

  it("genera una bozza incrementale da testo, copertina e risposta finale", () => {
    const completed = communication({ coverImageUrl: "/cover.png", coverStatus: "ready" });
    assistant["generate"].mockReturnValue(of(
      { status: "Testo pronto", phase: "generating-cover", communicationId: 7, text: { title: null, body: "Corpo" } },
      { status: "Cover pronta", phase: "generating-cover", communicationId: 7, cover: { coverImageUrl: "/cover.png", coverStatus: "ready", coverError: null } },
      { status: "Completata", phase: "completed", communicationId: 7, communication: completed }
    ));
    const page = createPage();

    page["generate"](draftForm);

    expect(assistant["generate"]).toHaveBeenCalledWith(draftForm);
    expect(page["phase"]()).toBe("completed");
    expect(page["status"]()).toBe("Completata");
    expect(page["previewDraft"]()).toMatchObject({ id: 7, title: "Nuova area documentale", coverImageUrl: "/cover.png" });
    expect(page["isGenerating"]()).toBe(false);
  });

  it("gestisce la copertina arrivata prima del testo con valori di fallback", () => {
    assistant["generate"].mockReturnValue(of({
      status: "Cover non disponibile",
      phase: "generating-cover",
      communicationId: 7,
      cover: { coverImageUrl: null, coverStatus: "failed", coverError: "errore cover" }
    }));
    const page = createPage();

    page["generate"](draftForm);

    expect(page["previewDraft"]()).toMatchObject({
      id: 7,
      title: "",
      body: "",
      status: "draft",
      coverStatus: "failed",
      coverError: "errore cover"
    });
    expect(page["isGenerating"]()).toBe(true);
  });

  it("espone l'errore della richiesta di generazione", () => {
    assistant["generate"].mockReturnValue(throwError(() => new Error("AI offline")));
    const page = createPage();

    page["generate"](draftForm);

    expect(page["phase"]()).toBe("failed");
    expect(page["status"]()).toBe("AI offline");
  });

  it("rigenera solo quando esiste una bozza e gestisce successo ed errore", () => {
    const page = createPage();
    page["regenerate"]();
    expect(assistant["regenerate"]).not.toHaveBeenCalled();

    setDraft(page);
    assistant["regenerate"].mockReturnValue(of({ status: "Rigenerata", phase: "completed", communicationId: 7 }));
    page["regenerate"]();
    expect(assistant["regenerate"]).toHaveBeenCalledWith(7);
    expect(page["status"]()).toBe("Rigenerata");

    assistant["regenerate"].mockReturnValue(throwError(() => new Error("rigenerazione fallita")));
    page["regenerate"]();
    expect(page["phase"]()).toBe("failed");
    expect(page["status"]()).toBe("rigenerazione fallita");
  });

  it("valuta una bozza una sola volta e aggiorna selezione e stato", () => {
    const page = createPage();
    page["rateDraft"]({ rating: 5 });
    expect(assistant["rate"]).not.toHaveBeenCalled();

    setDraft(page);
    assistant["rate"].mockReturnValue(of({ message: "Valutazione salvata", communication: communication({ rating: 5 }) }));
    page["rateDraft"]({ rating: 5, comment: "Ottima" });

    expect(assistant["rate"]).toHaveBeenCalledWith(7, { rating: 5, comment: "Ottima" });
    expect(page["previewDraft"]()?.rating).toBe(5);
    expect(page["selectedDraftId"]()).toBe(7);
    expect(page["isRating"]()).toBe(false);

    page["rateDraft"]({ rating: 4 });
    expect(assistant["rate"]).toHaveBeenCalledTimes(1);
  });

  it("espone l'errore di valutazione e conclude il caricamento", () => {
    const page = createPage();
    setDraft(page);
    assistant["rate"].mockReturnValue(throwError(() => new Error("rating fallito")));

    page["rateDraft"]({ rating: 2 });

    expect(page["rateError"]()).toBe("rating fallito");
    expect(page["isRating"]()).toBe(false);
  });

  it("seleziona una bozza e azzera l'errore rating", () => {
    const page = createPage();
    page["rateError"].set("errore precedente");

    page["selectDraft"](12);

    expect(page["selectedDraftId"]()).toBe(12);
    expect(page["rateError"]()).toBeNull();
  });

  it("carica e rimuove la copertina aggiornando sempre lo stato busy", () => {
    const page = createPage();
    const file = new File(["cover"], "cover.png", { type: "image/png" });
    page["uploadCover"](file);
    page["removeCover"]();
    expect(assistant["updateCoverImage"]).not.toHaveBeenCalled();
    expect(assistant["removeCoverImage"]).not.toHaveBeenCalled();

    setDraft(page);
    assistant["updateCoverImage"].mockReturnValue(of({ message: "Cover aggiornata", communication: communication({ coverStatus: "ready" }) }));
    assistant["removeCoverImage"].mockReturnValue(of({ message: "Cover rimossa", communication: communication() }));

    page["uploadCover"](file);
    expect(assistant["updateCoverImage"]).toHaveBeenCalledWith(7, file);
    expect(page["status"]()).toBe("Cover aggiornata");
    expect(page["isUpdatingCover"]()).toBe(false);

    page["removeCover"]();
    expect(assistant["removeCoverImage"]).toHaveBeenCalledWith(7);
    expect(page["status"]()).toBe("Cover rimossa");
    expect(page["isUpdatingCover"]()).toBe(false);
  });

  it("gestisce gli errori di caricamento e rimozione copertina", () => {
    const page = createPage();
    setDraft(page);
    assistant["updateCoverImage"].mockReturnValue(throwError(() => new Error("upload fallito")));
    assistant["removeCoverImage"].mockReturnValue(throwError(() => new Error("rimozione fallita")));

    page["uploadCover"](new File(["x"], "x.png"));
    expect(page["status"]()).toBe("upload fallito");
    expect(page["isUpdatingCover"]()).toBe(false);

    page["removeCover"]();
    expect(page["status"]()).toBe("rimozione fallita");
    expect(page["isUpdatingCover"]()).toBe(false);
  });

  it("salva le modifiche oppure espone l'errore", () => {
    const page = createPage();
    assistant["update"].mockReturnValue(of({ communication: communication({ title: "Titolo aggiornato" }) }));

    page["saveDraft"]({ communicationId: 7, payload: { title: "Titolo aggiornato", body: "Corpo" } });

    expect(page["previewDraft"]()?.title).toBe("Titolo aggiornato");
    expect(page["selectedDraftId"]()).toBe(7);
    expect(page["isSavingDraft"]()).toBe(false);

    assistant["update"].mockReturnValue(throwError(() => new Error("salvataggio fallito")));
    page["saveDraft"]({ communicationId: 7, payload: { title: "X", body: "Y" } });
    expect(page["saveDraftError"]()).toBe("salvataggio fallito");
    expect(page["isSavingDraft"]()).toBe(false);
  });

  it("scarta una bozza o mantiene l'anteprima in caso di errore", () => {
    const page = createPage();
    page["discard"]();
    expect(assistant["discard"]).not.toHaveBeenCalled();

    setDraft(page);
    assistant["discard"].mockReturnValue(of({ message: "Bozza eliminata" }));
    page["discard"]();
    expect(page["previewDraft"]()).toBeNull();
    expect(page["status"]()).toBe("Bozza eliminata");
    expect(page["isDiscarding"]()).toBe(false);

    setDraft(page);
    assistant["discard"].mockReturnValue(throwError(() => new Error("scarto fallito")));
    page["discard"]();
    expect(page["status"]()).toBe("scarto fallito");
    expect(page["previewDraft"]()).not.toBeNull();
  });

  it("salva una bozza nello storico (UC-9) o mantiene lo stato in caso di errore", () => {
    const page = createPage();
    page["saveToHistory"]();
    expect(assistant["saveToHistory"]).not.toHaveBeenCalled();

    setDraft(page);
    assistant["saveToHistory"].mockReturnValue(
      of({ message: "Bozza salvata nello storico.", communication: communication({ status: "Approvata", statusValue: "approved" }) })
    );
    page["saveToHistory"]();
    expect(page["status"]()).toBe("Bozza salvata nello storico.");
    expect(page["previewDraft"]()?.status).toBe("Approvata");
    expect(page["isSavingToHistory"]()).toBe(false);

    setDraft(page);
    assistant["saveToHistory"].mockReturnValue(throwError(() => new Error("salvataggio fallito")));
    page["saveToHistory"]();
    expect(page["status"]()).toBe("salvataggio fallito");
  });

  it("salva la configurazione del prompt o segnala l'errore (UC-19)", () => {
    const page = createPage();
    assistant["saveConfiguration"].mockReturnValue(of({ message: "Configurazione salvata." }));

    page["saveConfiguration"]({ prompt: "Un prompt qualsiasi lungo abbastanza", tone: "Tecnico", style: "Avviso operativo" });

    expect(page["status"]()).toBe("Configurazione salvata.");
    expect(page["isSavingConfiguration"]()).toBe(false);

    assistant["saveConfiguration"].mockReturnValue(throwError(() => new Error("salvataggio config fallito")));
    page["saveConfiguration"]({ prompt: "Un prompt qualsiasi lungo abbastanza", tone: "Tecnico", style: "Avviso operativo" });

    expect(page["saveConfigurationError"]()).toBe("salvataggio config fallito");
  });

  it("filtra le configurazioni salvate con gli stessi filtri dello storico", () => {
    promptConfigurations.set([
      { id: 1, name: "Ferie estive", prompt: "Avviso sulle ferie estive", tone: "Empatico", style: "Avviso operativo", createdAt: "2026-03-04" },
      { id: 2, name: "Manutenzione", prompt: "Comunicazione tecnica di manutenzione", tone: "Tecnico", style: "Testo informativo", createdAt: "2026-05-01" }
    ]);
    const page = createPage();

    expect(page["filteredPromptConfigurations"]()).toHaveLength(2);

    page["activeFilters"].set({ keyword: "ferie" });
    expect(page["filteredPromptConfigurations"]().map((c) => c.id)).toEqual([1]);

    page["activeFilters"].set({ tone: "Tecnico" });
    expect(page["filteredPromptConfigurations"]().map((c) => c.id)).toEqual([2]);

    page["activeFilters"].set({ style: "Avviso operativo" });
    expect(page["filteredPromptConfigurations"]().map((c) => c.id)).toEqual([1]);

    page["activeFilters"].set({ date: "2026-05-01" });
    expect(page["filteredPromptConfigurations"]().map((c) => c.id)).toEqual([2]);

    page["activeFilters"].set({ keyword: "nessuna corrispondenza" });
    expect(page["filteredPromptConfigurations"]()).toEqual([]);
  });

  it("riusa una configurazione salvata senza chiamare il backend (UC-19)", () => {
    const page = createPage();

    page["useConfiguration"]({
      id: 1,
      name: "Ferie estive",
      prompt: "Prompt della configurazione salvata",
      tone: "Empatico",
      style: "Avviso operativo"
    });

    expect(page["prefillPayload"]()).toEqual({
      prompt: "Prompt della configurazione salvata",
      tone: "Empatico",
      style: "Avviso operativo"
    });
    expect(assistant["generate"]).not.toHaveBeenCalled();
  });

  it("elimina una configurazione salvata o segnala l'errore", () => {
    const page = createPage();
    page["confirmingConfigDeleteId"].set(3);
    assistant["deleteConfiguration"].mockReturnValue(of({ message: "Configurazione eliminata." }));

    page["deleteConfiguration"](3);

    expect(page["status"]()).toBe("Configurazione eliminata.");
    expect(page["confirmingConfigDeleteId"]()).toBeNull();
    expect(page["isDeletingConfig"]()).toBe(false);

    assistant["deleteConfiguration"].mockReturnValue(throwError(() => new Error("eliminazione config fallita")));
    page["deleteConfiguration"](3);

    expect(page["status"]()).toBe("eliminazione config fallita");
  });

  it("elimina dallo storico e pulisce le anteprime collegate", () => {
    const page = createPage();
    setDraft(page);
    page["selectedDraftId"].set(7);
    page["confirmingDeleteId"].set(7);
    assistant["deleteFromHistory"].mockReturnValue(of({ message: "Eliminata" }));

    page["deleteHistoryItem"](7);

    expect(page["selectedDraftId"]()).toBeNull();
    expect(page["latestDraft"]()).toBeNull();
    expect(page["confirmingDeleteId"]()).toBeNull();
    expect(page["isDeletingHistoryItem"]()).toBe(false);
  });

  it("non pulisce una anteprima diversa e gestisce l'errore di eliminazione", () => {
    const page = createPage();
    setDraft(page, { id: 9 });
    page["selectedDraftId"].set(9);
    assistant["deleteFromHistory"].mockReturnValue(throwError(() => new Error("delete fallita")));

    page["deleteHistoryItem"](7);

    expect(page["latestDraft"]()?.id).toBe(9);
    expect(page["selectedDraftId"]()).toBe(9);
    expect(page["status"]()).toBe("delete fallita");
    expect(page["isDeletingHistoryItem"]()).toBe(false);
  });

  it("aggiunge e rimuove una comunicazione dai preferiti", () => {
    const page = createPage();
    const notFavorite = communication({ id: 7, isFavorite: false });

    assistant["favorite"].mockReturnValue(of({ message: "Generazione aggiunta ai preferiti." }));
    page["toggleFavorite"](notFavorite);

    expect(assistant["favorite"]).toHaveBeenCalledWith(7);
    expect(assistant["unfavorite"]).not.toHaveBeenCalled();
    expect(page["status"]()).toBe("Generazione aggiunta ai preferiti.");
    expect(page["togglingFavoriteId"]()).toBeNull();

    const favorite = communication({ id: 7, isFavorite: true });
    assistant["unfavorite"].mockReturnValue(of({ message: "Generazione rimossa dai preferiti." }));
    page["toggleFavorite"](favorite);

    expect(assistant["unfavorite"]).toHaveBeenCalledWith(7);
    expect(page["status"]()).toBe("Generazione rimossa dai preferiti.");
    expect(page["togglingFavoriteId"]()).toBeNull();
  });

  it("espone l'errore di aggiornamento dei preferiti", () => {
    const page = createPage();
    assistant["favorite"].mockReturnValue(throwError(() => new Error("preferiti non disponibili")));

    page["toggleFavorite"](communication({ id: 7, isFavorite: false }));

    expect(page["status"]()).toBe("preferiti non disponibili");
    expect(page["togglingFavoriteId"]()).toBeNull();
  });

  it("azzera tutti i filtri e aggiorna il flag", () => {
    const page = createPage();
    page["activeFilters"].set({ keyword: "ferie" });
    page["filterForm"].setValue({ keyword: "ferie", tone: "Empatico", style: "", date: "2026-07-31" });
    expect(page["hasActiveFilters"]()).toBe(true);

    page["resetFilters"]();

    expect(page["filterForm"].getRawValue()).toEqual({ keyword: "", tone: "", style: "", date: "" });
  });
});
