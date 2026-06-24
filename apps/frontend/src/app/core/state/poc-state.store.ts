import { Injectable, computed, inject, signal } from "@angular/core";
import { retry } from "rxjs";
import { AlittlebytePoCAPIService } from "../../../api/generated/poc-api";
import type { PocState, SubDocument } from "../../../api/generated/model";
import { getApiErrorMessage } from "../errors/api-error";

/**
 * Sorgente unica dello stato applicativo (assistant + co-pilot), condivisa fra
 * tutte le viste. Essendo un singleton di root, lo stato sopravvive ai cambi di
 * rotta e il passaggio fra viste resta istantaneo. Le mutazioni (genera, upload,
 * revisione, eliminazione) rimpiazzano lo stato con quello autorevole restituito
 * dal backend.
 */
@Injectable({ providedIn: "root" })
export class PocStateStore {
  private readonly api = inject(AlittlebytePoCAPIService);

  private readonly _state = signal<PocState | null>(null);
  private readonly _loading = signal(false);
  private readonly _error = signal<string | null>(null);
  private loadRequested = false;

  readonly state = this._state.asReadonly();
  readonly loading = this._loading.asReadonly();
  readonly error = this._error.asReadonly();

  readonly documents = computed<SubDocument[]>(() => this._state()?.copilot.documents ?? []);
  readonly history = computed(() => this._state()?.assistant.history ?? []);
  readonly assistantMetrics = computed(() => this._state()?.assistant.metrics ?? []);
  readonly copilotMetrics = computed(() => this._state()?.copilot.metrics ?? []);

  /** Carica lo stato una sola volta (al primo montaggio della shell). */
  loadOnce(): void {
    if (this.loadRequested) {
      return;
    }

    this.loadRequested = true;
    this.reload();
  }

  /** Ricarica lo stato applicando una sola retry per errori temporanei. */
  reload(): void {
    this._loading.set(true);
    this._error.set(null);

    this.api
      .getPocState()
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
  setState(state: PocState): void {
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
