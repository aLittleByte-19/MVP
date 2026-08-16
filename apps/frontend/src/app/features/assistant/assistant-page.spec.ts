import { signal } from "@angular/core";
import { TestBed, fakeAsync, tick } from "@angular/core/testing";
import { of, throwError } from "rxjs";
import type { Communication, PromptConfiguration } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { AssistantPage } from "./assistant-page";
import { AssistantPageViewModel } from "./assistant-page.view-model";
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

/**
 * Test della View (Component Angular): copre solo il collante col template
 * e il cablaggio che richiede un injection context (effect(),
 * takeUntilDestroyed()) — non richiede TestBed per costruire il
 * ViewModel. La logica di dominio (generazione, valutazione, storico...)
 * è testata senza Angular in assistant-page.view-model.spec.ts.
 */
describe("AssistantPage", () => {
  let history: ReturnType<typeof signal<Communication[]>>;
  let error: ReturnType<typeof signal<string | null>>;
  let promptConfigurations: ReturnType<typeof signal<PromptConfiguration[]>>;
  let assistant: Record<string, jest.Mock>;

  beforeEach(() => {
    history = signal<Communication[]>([]);
    error = signal<string | null>(null);
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
    TestBed.configureTestingModule({
      providers: [
        { provide: MvpStateStore, useValue: { history, error, promptConfigurations } },
        { provide: AssistantService, useValue: assistant }
      ]
    });
  });

  function createPage(): AssistantPage {
    const fixture = TestBed.createComponent(AssistantPage);
    fixture.detectChanges();
    return fixture.componentInstance;
  }

  it("costruisce il ViewModel e delega ad esso tutto lo stato applicativo", () => {
    const page = createPage();

    expect(page["vm"]).toBeInstanceOf(AssistantPageViewModel);
  });

  it("carica lo storico filtrato tramite l'effect e lo rende nel template", () => {
    const record = communication();
    assistant["searchCommunications"].mockReturnValue(of([record]));
    const fixture = TestBed.createComponent(AssistantPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["vm"].filteredCommunications()).toEqual([record]);
    expect(fixture.nativeElement.textContent).toContain("1 record");
  });

  it("propaga l'errore di ricerca dell'effect al ViewModel", () => {
    assistant["searchCommunications"].mockReturnValue(throwError(() => new Error("storico offline")));
    const fixture = TestBed.createComponent(AssistantPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["vm"].historyError()).toBe("storico offline");
    expect(fixture.nativeElement.textContent).toContain("storico offline");
  });

  it("rende il banner d'errore dello store", () => {
    error.set("Backend non disponibile");
    const fixture = TestBed.createComponent(AssistantPage);
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain("Backend non disponibile");
  });

  it("propaga il form dei filtri, con debounce, al ViewModel", fakeAsync(() => {
    const fixture = TestBed.createComponent(AssistantPage);
    fixture.detectChanges();
    const page = fixture.componentInstance;
    assistant["searchCommunications"].mockClear();

    page["filterForm"].controls.keyword.setValue("ferie");
    tick(300);

    expect(page["vm"].activeFilters()).toEqual({ keyword: "ferie", tone: "", style: "", date: "" });
  }));

  it("azzera i filtri del form", () => {
    const page = createPage();
    page["filterForm"].controls.keyword.setValue("ferie");

    page["resetFilters"]();

    expect(page["filterForm"].getRawValue()).toEqual({ keyword: "", tone: "", style: "", date: "" });
  });
});
