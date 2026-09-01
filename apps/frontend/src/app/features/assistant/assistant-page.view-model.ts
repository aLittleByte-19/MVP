import { type Signal, type WritableSignal, computed, signal } from "@angular/core";
import { type Subscription, finalize } from "rxjs";
import type {
  Communication,
  GenerateCommunicationRequestStyle,
  GenerateCommunicationRequestTone,
  PromptConfiguration,
  SavePromptConfigurationRequest,
  UpdateCommunicationRequest
} from "../../../api/generated/model";
import { getApiErrorMessage } from "../../core/errors/api-error";
import { MvpStateStore, type AssistantMetricsFilters } from "../../core/state/mvp-state.store";
import type { CompositionSlice } from "../../shared/components/metric-composition/metric-composition";
import type { MetricPresentation } from "../../shared/components/metrics-panel/metrics-panel";
import type {
  CommunicationDraftForm,
  CommunicationGenerationPhase,
  CommunicationGenerationProgress,
  GeneratedDraft,
  RateDraftPayload
} from "./assistant.model";
import { AssistantService, type CommunicationFilters } from "./data/assistant.service";

/**
 * Presentation Model dell'AI Assistant: nessun `@Component`/`inject()`, solo
 * `signal`/`computed` — istanziabile con `new`, testabile senza `TestBed`.
 * Non chiama mai la View: uno scroll richiesto si scrive su
 * `pendingScrollTarget` e la View lo consuma da un suo `effect()`.
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

  /** Scroll richiesto dalla View; azzerato dopo l'uso perché un effect() reagisce solo ai cambi di valore. */
  readonly pendingScrollTarget: WritableSignal<string | null> = signal(null);

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

  readonly error: Signal<string | null> = computed(() => this.store.error() ?? this.store.filteredAssistantError());
  readonly loading: Signal<boolean> = computed(() => this.store.loading() || this.store.filteredAssistantLoading());

  /** Filtri della sezione Metriche (RF38-OB..RF41-OB), separati da quelli dello storico qui sopra. */
  readonly metricsFilters: WritableSignal<AssistantMetricsFilters> = signal({});
  readonly hasActiveMetricsFilters: Signal<boolean> = computed(() => Object.keys(this.metricsFilters()).length > 0);

  /** `assistant.drafts` escluso: vive come priorità nella Overview, qui è solo la parte "in bozza" sotto. */
  readonly metrics = computed(() =>
    (this.store.filteredAssistantState()?.metrics ?? []).filter((metric) => metric.key !== "assistant.drafts")
  );

  /** Quattro schede da 2 celle: multiplo di 4 richiesto dalla griglia (metrics-panel.css), niente righe spaiate. */
  readonly metricsPresentation = computed<Record<string, MetricPresentation>>(() => ({
    "assistant.total": { kind: "trend", span: 2 },
    "assistant.prompt_configurations": { kind: "trend", span: 2 },
    "assistant.rated": {
      kind: "share",
      span: 2,
      restTone: "neutral",
      restNoun: "senza voto"
    },
    "assistant.rating_average": { kind: "stars", span: 2, max: 5 }
  }));

  /** UC-27.3: ultimi feedback con voto e commento, filtrati come il resto della sezione. */
  readonly recentFeedback: Signal<Communication[]> = computed(
    () => this.store.filteredAssistantState()?.recentFeedback ?? []
  );

  /** RF34-OB: export PDF del report metriche. Percorso relativo, stesso-origine dietro Traefik/edge-cdn. */
  readonly metricsReportExportUrl = "/api/v1/assistant/metrics/export";

  /** Esito delle bozze: in bozza vs. decise (nessun'altra vista lo mostra oggi). */
  readonly draftComposition = computed<CompositionSlice[]>(() => {
    const metrics = this.store.filteredAssistantState()?.metrics ?? [];
    const valueOf = (key: string): number => {
      const found = metrics.find((metric) => metric.key === key);

      return typeof found?.value === "number" ? found.value : 0;
    };

    const total = valueOf("assistant.total");
    const drafts = valueOf("assistant.drafts");

    return [
      { label: "In bozza", value: drafts },
      // Il backend espone il totale e le bozze: le altre sono le comunicazioni
      // su cui una decisione è già stata presa.
      { label: "Decise", value: Math.max(0, total - drafts) }
    ];
  });

  private searchSubscription: Subscription | null = null;

  constructor(
    private readonly assistant: AssistantService,
    private readonly store: MvpStateStore
  ) {}

  setActiveFilters(filters: CommunicationFilters): void {
    this.activeFilters.set(filters);
  }

  setMetricsFilters(filters: AssistantMetricsFilters): void {
    this.metricsFilters.set(filters);
  }

  /** Rilegge la sezione Metriche, col filtro attivo corrente (RF38-OB). */
  refreshFilteredMetrics(): void {
    this.store.reloadFilteredAssistantMetrics(this.metricsFilters());
  }

  /** Annulla la ricerca precedente ancora in volo, cosi' una risposta tardiva non sovrascrive quella nuova. */
  reload(): void {
    this.searchSubscription?.unsubscribe();
    this.searchSubscription = this.assistant.searchCommunications(this.activeFilters()).subscribe({
      next: (communications) => this.setFilteredCommunications(communications),
      error: (error: unknown) => this.handleHistoryError(error)
    });
  }

  /** Per il pulsante "Riprova": ricarica stato condiviso + metriche filtrate, non lo storico (vedi reload()). */
  reloadState(): void {
    this.store.reload();
    this.refreshFilteredMetrics();
  }

  /** Annulla solo la ricerca in lettura; le scritture (generate, discard...) completano anche a pagina lasciata. */
  destroy(): void {
    this.searchSubscription?.unsubscribe();
  }

  private setFilteredCommunications(communications: Communication[]): void {
    this.filteredCommunications.set(communications);
    this.historyError.set(null);
  }

  private handleHistoryError(error: unknown): void {
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
    // Stesso id: resta su latestDraft cosi' testo/copertina si aggiornano per gradi come in generate().
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
    this.pendingScrollTarget.set("assistant-review");
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
          this.pendingScrollTarget.set("assistant-compose");
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
    this.pendingScrollTarget.set("assistant-compose");
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
      this.pendingScrollTarget.set("assistant-review");
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
}
