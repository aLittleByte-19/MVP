import { Injectable, computed, inject, signal, untracked } from "@angular/core";
import { type Subscription, retry } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../api/generated/mvp-api";
import type { Metric, MvpState, SubDocument } from "../../../api/generated/model";
import { getApiErrorMessage } from "../errors/api-error";

/** Filtri della sezione Metriche AI Assistant (RF38-OB..RF41-OB); tono/stile non esistono sui documenti Co-Pilot. */
export interface AssistantMetricsFilters {
  readonly tone?: string | null;
  readonly style?: string | null;
  readonly dateFrom?: string | null;
  readonly dateTo?: string | null;
}

/** Sorgente unica dello stato applicativo (assistant + co-pilot), condivisa fra tutte le viste. */
@Injectable({ providedIn: "root" })
export class MvpStateStore {
  private readonly api = inject(AlittlebyteMVPAPIService);

  private readonly _state = signal<MvpState | null>(null);
  private readonly _loading = signal(false);
  private readonly _error = signal<string | null>(null);

  /** Separata da `_state` (RF38-OB): filtrare qui non deve toccare i numeri di Overview/Co-Pilot. */
  private readonly _filteredAssistantState = signal<MvpState["assistant"] | null>(null);
  private readonly _filteredAssistantLoading = signal(false);
  private readonly _filteredAssistantError = signal<string | null>(null);
  private filteredAssistantMetricsSubscription: Subscription | null = null;

  readonly state = this._state.asReadonly();
  readonly loading = this._loading.asReadonly();
  readonly error = this._error.asReadonly();

  readonly filteredAssistantState = this._filteredAssistantState.asReadonly();
  readonly filteredAssistantLoading = this._filteredAssistantLoading.asReadonly();
  readonly filteredAssistantError = this._filteredAssistantError.asReadonly();

  readonly documents = computed<SubDocument[]>(() => this._state()?.copilot.documents ?? []);
  readonly history = computed(() => this._state()?.assistant.history ?? []);
  readonly recentFeedback = computed(() => this._state()?.assistant.recentFeedback ?? []);
  readonly promptConfigurations = computed(() => this._state()?.assistant.promptConfigurations ?? []);
  readonly assistantMetrics = computed(() => this._state()?.assistant.metrics ?? []);
  readonly copilotMetrics = computed(() => this._state()?.copilot.metrics ?? []);

  /** Le metriche sono conteggi sull'intero tenant: non vanno ricalcolate sugli elenchi, finestre parziali. */
  metric(key: string): number {
    const found = this.metricEntry(key);

    return typeof found?.value === "number" ? found.value : 0;
  }

  /** A differenza di `metric()`, non collassa l'assenza su `0`: una scheda in caricamento non deve mostrare un dato che non ha. */
  metricEntry(key: string): Metric | null {
    return (
      [...this.assistantMetrics(), ...this.copilotMetrics()].find((entry) => entry.key === key) ?? null
    );
  }

  /** Carica lo stato al primo montaggio; ritenta se il primo tentativo e' fallito. */
  loadOnce(): void {
    if (this._loading() || this._state() !== null) {
      return;
    }

    this.reload();
  }

  /** Ignora una chiamata mentre una richiesta e' gia' in volo (es. doppio click su "Riprova"). */
  reload(): void {
    if (this._loading()) {
      return;
    }

    this._loading.set(true);
    this._error.set(null);

    this.api
      .getMvpState()
      .pipe(retry(1))
      .subscribe({
        next: (state) => {
          this._state.set(state);
          this._loading.set(false);
        },
        error: (error: unknown) => {
          this._error.set(getApiErrorMessage(error));
          this._loading.set(false);
        }
      });
  }

  /**
   * Fetta AI Assistant separata da `_state` (RF38-OB): filtrare qui non tocca Overview/Co-Pilot.
   * Annulla la richiesta precedente ancora in volo invece di ignorare la nuova.
   * `untracked()`: chiamato da un `effect()`, una lettura di segnale qui fuori diventerebbe una sua dipendenza e ricreerebbe il ciclo infinito gia' visto su questo metodo.
   */
  reloadFilteredAssistantMetrics(filters: AssistantMetricsFilters): void {
    untracked(() => {
      this.filteredAssistantMetricsSubscription?.unsubscribe();
      this._filteredAssistantLoading.set(true);
      this._filteredAssistantError.set(null);

      this.filteredAssistantMetricsSubscription = this.api
        .getMvpState({
          tone: filters.tone ?? undefined,
          style: filters.style ?? undefined,
          dateFrom: filters.dateFrom ?? undefined,
          dateTo: filters.dateTo ?? undefined
        })
        .pipe(retry(1))
        .subscribe({
          next: (state) => {
            this._filteredAssistantState.set(state.assistant);
            this._filteredAssistantLoading.set(false);
          },
          error: (error: unknown) => {
            this._filteredAssistantError.set(getApiErrorMessage(error));
            this._filteredAssistantLoading.set(false);
          }
        });
    });
  }

  /** Rimpiazza lo stato con quello autorevole restituito da una mutazione. */
  setState(state: MvpState): void {
    this._state.set(state);
  }

  /** Inserisce/aggiorna in testa un sotto-documento dallo stream SSE, preservando il resto dello stato. */
  upsertDocument(document: SubDocument): void {
    this._state.update((current) => {
      if (!current) {
        return current;
      }

      return {
        ...current,
        copilot: {
          ...current.copilot,
          documents: [document, ...current.copilot.documents.filter((item) => item.id !== document.id)]
        }
      };
    });
  }
}
