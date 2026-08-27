import { Injectable, computed, inject, signal, untracked } from "@angular/core";
import { type Subscription, retry } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../api/generated/mvp-api";
import type { Metric, MvpState, SubDocument } from "../../../api/generated/model";
import { getApiErrorMessage } from "../errors/api-error";

/**
 * Filtri della sezione Metriche dell'AI Assistant (RF38-OB..RF41-OB). Tono e
 * stile non esistono sui documenti del Co-Pilot: valgono solo sulla fetta di
 * stato alimentata dall'AI Assistant, mai su `state`/`reload()`, condivisi
 * con Overview e Co-Pilot senza filtro.
 */
export interface AssistantMetricsFilters {
  readonly tone?: string | null;
  readonly style?: string | null;
  readonly dateFrom?: string | null;
  readonly dateTo?: string | null;
}

/**
 * Sorgente unica dello stato applicativo (assistant + co-pilot), condivisa fra
 * tutte le viste. Essendo un singleton di root, lo stato sopravvive ai cambi di
 * rotta e il passaggio fra viste resta istantaneo. Le mutazioni (genera, upload,
 * revisione, eliminazione) rimpiazzano lo stato con quello autorevole restituito
 * dal backend.
 */
@Injectable({ providedIn: "root" })
export class MvpStateStore {
  private readonly api = inject(AlittlebyteMVPAPIService);

  private readonly _state = signal<MvpState | null>(null);
  private readonly _loading = signal(false);
  private readonly _error = signal<string | null>(null);

  /**
   * Fetta di stato separata da `_state` per la sezione Metriche filtrata
   * dell'AI Assistant (RF38-OB): `_state` resta la fotografia non filtrata
   * che Overview e Co-Pilot condividono, cosi' applicare un filtro qui non
   * cambia i numeri sotto i loro pannelli.
   */
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

  /**
   * Valore di una metrica per chiave stabile. Le metriche sono conteggi
   * calcolati dal backend sull'intero tenant: vanno lette da qui e non
   * ricalcolate sugli elenchi, che sono finestre parziali (history 10,
   * documents 40) e darebbero numeri sbagliati appena i dati crescono.
   */
  metric(key: string): number {
    const found = this.metricEntry(key);

    return typeof found?.value === "number" ? found.value : 0;
  }

  /**
   * Metrica completa per chiave, oppure `null` finche' lo stato non e' stato
   * caricato o se la chiave non esiste.
   *
   * Distinto da `metric()`, che collassa l'assenza su `0`: una scheda che
   * mostra `0` durante il caricamento afferma un dato che non ha ancora, ed e'
   * esattamente cio' che i KPI della Overview facevano. Serve anche a leggere
   * `history`, che il conteggio da solo non porta.
   */
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

  /**
   * Ricarica lo stato applicando una sola retry per errori temporanei.
   *
   * Ignora una chiamata mentre una richiesta e' gia' in volo (es. un doppio
   * click su "Riprova"): senza questa guardia partirebbero due `GET /state`
   * in parallelo, l'ultima risposta vincerebbe comunque ma la richiesta in
   * piu' sarebbe sprecata — la stessa guardia che `loadOnce()` applica gia'.
   */
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
   * Ricarica solo la fetta AI Assistant della sezione Metriche, filtrata
   * (RF38-OB), su `_state` separato: non tocca cio' che Overview e Co-Pilot
   * leggono da `state()`.
   *
   * Annulla la richiesta filtrata precedente ancora in volo prima di
   * avviarne una nuova (stesso schema di `AssistantPageViewModel.reload()`
   * per lo storico) invece di ignorare la nuova richiesta come faceva prima:
   * un cambio di filtro mentre la risposta al cambio precedente non e'
   * ancora arrivata non deve andare perso, altrimenti il pannello resta
   * fermo su un filtro che i controlli non mostrano piu'.
   *
   * Tutto il corpo gira dentro `untracked()`: viene chiamato da un
   * `effect()` sul componente (l'unico modo per farlo scattare ad ogni
   * cambio filtro), e una futura lettura di segnale aggiunta qui dentro
   * senza `untracked()` diventerebbe una dipendenza di quell'effect — la
   * stessa causa del ciclo di richieste che non si fermava mai, gia' visto
   * su questo stesso metodo.
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

  /**
   * Inserisce/aggiorna in testa un sotto-documento ricevuto dallo stream SSE,
   * preservando il resto dello stato (aggiornamento incrementale dell'upload).
   */
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
