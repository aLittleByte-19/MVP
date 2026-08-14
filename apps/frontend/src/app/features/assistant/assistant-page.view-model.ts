import { type Signal, type WritableSignal, computed, signal } from "@angular/core";
import { finalize } from "rxjs";
import type {
  Communication,
  GenerateCommunicationRequestStyle,
  GenerateCommunicationRequestTone,
  PromptConfiguration,
  SavePromptConfigurationRequest,
  UpdateCommunicationRequest
} from "../../../api/generated/model";
import { getApiErrorMessage } from "../../core/errors/api-error";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import type {
  CommunicationDraftForm,
  CommunicationGenerationPhase,
  CommunicationGenerationProgress,
  GeneratedDraft,
  RateDraftPayload
} from "./assistant.model";
import { AssistantService, type CommunicationFilters } from "./data/assistant.service";

/**
 * ViewModel puro dell'AI Assistant (MVVM in senso classico): nessun
 * riferimento ad Angular (niente `inject()`, decoratori, injection
 * context) — le dipendenze arrivano dal costruttore, non da `inject()`,
 * quindi la classe è istanziabile con `new` e testabile senza `TestBed`.
 * `AssistantPage` (la View) resta l'unico punto accoppiato ad Angular: si
 * procura le dipendenze con `inject()` e costruisce questa istanza.
 *
 * `effect()`/`takeUntilDestroyed()` restano nella View perché richiedono un
 * injection context che questa classe non ha per costruzione — vedi
 * `setFilteredCommunications()`/`handleHistoryError()`, che ricevono il
 * risultato già calcolato invece di gestire loro stessi la sottoscrizione.
 */
export class AssistantPageViewModel {
  readonly phase: WritableSignal<CommunicationGenerationPhase | "idle"> = signal("idle");
  readonly isGenerating: Signal<boolean> = computed(
    () => this.phase() === "queued" || this.phase() === "generating-text" || this.phase() === "generating-cover"
  );
  readonly isUpdatingCover = signal(false);
  readonly isDiscarding = signal(false);
  readonly isSavingToHistory = signal(false);
  readonly confirmingDeleteId: WritableSignal<number | null> = signal(null);
  readonly isDeletingHistoryItem = signal(false);
  readonly confirmingConfigDeleteId: WritableSignal<number | null> = signal(null);
  readonly isDeletingConfig = signal(false);
  readonly isRating = signal(false);
  readonly rateError: WritableSignal<string | null> = signal(null);
  readonly status = signal("In attesa di istruzioni.");
  readonly selectedDraftId: WritableSignal<number | null> = signal(null);
  readonly latestDraft: WritableSignal<GeneratedDraft | null> = signal(null);
  readonly isSavingDraft = signal(false);
  readonly saveDraftError: WritableSignal<string | null> = signal(null);
  readonly isSavingConfiguration = signal(false);
  readonly saveConfigurationError: WritableSignal<string | null> = signal(null);
  readonly prefillPayload: WritableSignal<CommunicationDraftForm | null> = signal(null);
  readonly promptConfigurations: Signal<PromptConfiguration[]> = computed(() => this.store.promptConfigurations());
  /** Le configurazioni salvate condividono gli stessi filtri dello storico contenuti. */
  readonly filteredPromptConfigurations: Signal<PromptConfiguration[]> = computed(() => {
    const filters = this.activeFilters();
    const keyword = filters.keyword?.trim().toLowerCase();

    return this.promptConfigurations().filter((configuration) => {
      if (
        keyword &&
        !configuration.name.toLowerCase().includes(keyword) &&
        !configuration.prompt.toLowerCase().includes(keyword)
      ) {
        return false;
      }

      if (filters.tone && configuration.tone !== filters.tone) {
        return false;
      }

      if (filters.style && configuration.style !== filters.style) {
        return false;
      }

      if (filters.date && configuration.createdAt !== filters.date) {
        return false;
      }

      return true;
    });
  });
  readonly togglingFavoriteId: WritableSignal<number | null> = signal(null);

  readonly previewDraft: Signal<GeneratedDraft | null> = computed(() => {
    const selectedId = this.selectedDraftId();

    if (selectedId === null) {
      return this.latestDraft();
    }

    const record = this.store.history().find((communication) => communication.id === selectedId);
    return record ? this.toDraft(record) : this.latestDraft();
  });

  readonly activeFilters: WritableSignal<CommunicationFilters> = signal({});
  readonly hasActiveFilters: Signal<boolean> = computed(() => Object.keys(this.activeFilters()).length > 0);
  readonly filteredCommunications: WritableSignal<Communication[]> = signal([]);
  readonly historyError: WritableSignal<string | null> = signal(null);

  constructor(
    private readonly assistant: AssistantService,
    private readonly store: MvpStateStore
  ) {}

  setActiveFilters(filters: CommunicationFilters): void {
    this.activeFilters.set(filters);
  }

  setFilteredCommunications(communications: Communication[]): void {
    this.filteredCommunications.set(communications);
    this.historyError.set(null);
  }

  handleHistoryError(error: unknown): void {
    this.historyError.set(getApiErrorMessage(error, "Storico comunicazioni non disponibile."));
  }

  generate(payload: CommunicationDraftForm): void {
    this.phase.set("queued");
    this.status.set("Generazione in corso.");
    this.selectedDraftId.set(null);
    this.latestDraft.set(null);
    this.rateError.set(null);

    this.assistant.generate(payload).subscribe({
      next: (progress) => this.handleProgress(progress),
      error: (error: unknown) => {
        this.phase.set("failed");
        this.status.set(getApiErrorMessage(error, "Generazione non disponibile."));
      }
    });
  }

  regenerate(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.phase.set("queued");
    this.status.set("Rigenerazione in corso.");
    // Stesso id, non una nuova bozza: manteniamo la visuale sull'ultima
    // versione (latestDraft) invece che sulla voce di storico selezionata,
    // cosi' il testo e la copertina si aggiornano per gradi come in generate().
    this.selectedDraftId.set(null);
    this.latestDraft.set(draft);

    this.assistant.regenerate(draft.id).subscribe({
      next: (progress) => this.handleProgress(progress),
      error: (error: unknown) => {
        this.phase.set("failed");
        this.status.set(getApiErrorMessage(error, "Rigenerazione non disponibile."));
      }
    });
  }

  rateDraft(payload: RateDraftPayload): void {
    const draft = this.previewDraft();
    if (!draft || draft.rating != null) {
      return;
    }

    this.isRating.set(true);
    this.rateError.set(null);

    this.assistant
      .rate(draft.id, payload)
      .pipe(finalize(() => this.isRating.set(false)))
      .subscribe({
        next: (response) => {
          const rated = this.toDraft(response.communication);
          this.latestDraft.set(rated);
          this.selectedDraftId.set(rated.id);
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.rateError.set(getApiErrorMessage(error, "Valutazione non disponibile. Riprova."));
        }
      });
  }

  selectDraft(communicationId: number): void {
    this.selectedDraftId.set(communicationId);
    this.rateError.set(null);
    this.scrollTo("assistant-review");
  }

  uploadCover(file: File): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isUpdatingCover.set(true);
    this.status.set("Caricamento immagine in corso.");

    this.assistant
      .updateCoverImage(draft.id, file)
      .pipe(finalize(() => this.isUpdatingCover.set(false)))
      .subscribe({
        next: (response) => {
          this.latestDraft.set(this.toDraft(response.communication));
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Aggiornamento immagine non disponibile."));
        }
      });
  }

  saveDraft(event: { communicationId: number; payload: UpdateCommunicationRequest }): void {
    this.saveDraftError.set(null);
    this.isSavingDraft.set(true);

    this.assistant
      .update(event.communicationId, event.payload)
      .pipe(finalize(() => this.isSavingDraft.set(false)))
      .subscribe({
        next: (response) => {
          this.latestDraft.set(this.toDraft(response.communication));
          this.selectedDraftId.set(response.communication.id);
        },
        error: (error: unknown) => {
          this.saveDraftError.set(getApiErrorMessage(error, "Salvataggio non disponibile."));
        }
      });
  }

  removeCover(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isUpdatingCover.set(true);
    this.status.set("Rimozione immagine in corso.");

    this.assistant
      .removeCoverImage(draft.id)
      .pipe(finalize(() => this.isUpdatingCover.set(false)))
      .subscribe({
        next: (response) => {
          this.latestDraft.set(this.toDraft(response.communication));
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Rimozione immagine non disponibile."));
        }
      });
  }

  discard(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isDiscarding.set(true);
    this.status.set("Eliminazione bozza in corso.");

    this.assistant
      .discard(draft.id)
      .pipe(finalize(() => this.isDiscarding.set(false)))
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
          this.selectedDraftId.set(null);
          this.latestDraft.set(null);
          this.scrollTo("assistant-compose");
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Eliminazione bozza non disponibile."));
        }
      });
  }

  saveToHistory(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isSavingToHistory.set(true);
    this.status.set("Salvataggio nello storico in corso.");

    this.assistant
      .saveToHistory(draft.id)
      .pipe(finalize(() => this.isSavingToHistory.set(false)))
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
          this.latestDraft.set(this.toDraft(response.communication));
          this.selectedDraftId.set(response.communication.id);
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Salvataggio nello storico non disponibile."));
        }
      });
  }

  /** Salva la configurazione corrente del prompt nello storico (UC-19). */
  saveConfiguration(payload: SavePromptConfigurationRequest): void {
    this.saveConfigurationError.set(null);
    this.isSavingConfiguration.set(true);

    this.assistant
      .saveConfiguration(payload)
      .pipe(finalize(() => this.isSavingConfiguration.set(false)))
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.saveConfigurationError.set(getApiErrorMessage(error, "Salvataggio della configurazione non disponibile."));
        }
      });
  }

  /** Riusa una configurazione di prompt salvata (UC-19): solo il form, nessuna chiamata al backend. */
  useConfiguration(configuration: PromptConfiguration): void {
    this.prefillPayload.set({
      prompt: configuration.prompt,
      tone: configuration.tone as GenerateCommunicationRequestTone,
      style: configuration.style as GenerateCommunicationRequestStyle
    });
    this.scrollTo("assistant-compose");
  }

  deleteConfiguration(configurationId: number): void {
    this.isDeletingConfig.set(true);

    this.assistant
      .deleteConfiguration(configurationId)
      .pipe(
        finalize(() => {
          this.isDeletingConfig.set(false);
          this.confirmingConfigDeleteId.set(null);
        })
      )
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Eliminazione della configurazione non disponibile."));
        }
      });
  }

  deleteHistoryItem(communicationId: number): void {
    this.isDeletingHistoryItem.set(true);

    this.assistant
      .deleteFromHistory(communicationId)
      .pipe(
        finalize(() => {
          this.isDeletingHistoryItem.set(false);
          this.confirmingDeleteId.set(null);
        })
      )
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
          // L'elemento eliminato non deve restare in anteprima.
          if (this.selectedDraftId() === communicationId) {
            this.selectedDraftId.set(null);
          }
          if (this.latestDraft()?.id === communicationId) {
            this.latestDraft.set(null);
          }
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Eliminazione dallo storico non disponibile."));
        }
      });
  }

  toggleFavorite(communication: Communication): void {
    this.togglingFavoriteId.set(communication.id);

    const request = communication.isFavorite
      ? this.assistant.unfavorite(communication.id)
      : this.assistant.favorite(communication.id);

    request.pipe(finalize(() => this.togglingFavoriteId.set(null))).subscribe({
      next: (response) => this.status.set(response.message),
      error: (error: unknown) => {
        this.status.set(getApiErrorMessage(error, "Aggiornamento preferiti non disponibile."));
      }
    });
  }

  private handleProgress(progress: CommunicationGenerationProgress): void {
    this.phase.set(progress.phase);
    this.status.set(progress.status);

    // Il testo arriva prima della copertina: la bozza si popola per gradi.
    if (progress.text) {
      this.latestDraft.set({
        id: progress.communicationId,
        title: progress.text.title ?? "",
        body: progress.text.body ?? "",
        status: "draft",
        coverStatus: "processing"
      });
      this.scrollTo("assistant-review");
    }

    if (progress.cover) {
      const current = this.latestDraft();
      this.latestDraft.set({
        id: progress.communicationId,
        title: current?.title ?? "",
        body: current?.body ?? "",
        status: current?.status ?? "draft",
        coverImageUrl: progress.cover.coverImageUrl ?? undefined,
        coverStatus: progress.cover.coverStatus,
        coverError: progress.cover.coverError ?? undefined
      });
    }

    if (progress.communication) {
      this.latestDraft.set(this.toDraft(progress.communication));
    }
  }

  private toDraft(communication: Communication): GeneratedDraft {
    return {
      id: communication.id,
      title: communication.title ?? "",
      body: communication.body ?? "",
      status: communication.status,
      coverImageUrl: communication.coverImageUrl ?? undefined,
      coverStatus: communication.coverStatus,
      coverError: communication.coverError ?? undefined,
      previewUrl: communication.previewUrl,
      exportUrl: communication.exportUrl,
      generationStatus: communication.generationStatus,
      rating: communication.rating ?? null,
      ratingComment: communication.ratingComment ?? null,
      ratedAt: communication.ratedAt ?? null,
      statusValue: communication.statusValue ?? "draft"
    };
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
