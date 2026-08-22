import { Injectable, computed, inject, signal } from "@angular/core";
import { retry } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../api/generated/mvp-api";
import type { Metric, MvpState, SubDocument } from "../../../api/generated/model";
import { getApiErrorMessage } from "../errors/api-error";

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

  readonly state = this._state.asReadonly();
  readonly loading = this._loading.asReadonly();
  readonly error = this._error.asReadonly();

  readonly documents = computed<SubDocument[]>(() => this._state()?.copilot.documents ?? []);
  readonly history = computed(() => this._state()?.assistant.history ?? []);
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

  /** Ricarica lo stato applicando una sola retry per errori temporanei. */
  reload(): void {
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
