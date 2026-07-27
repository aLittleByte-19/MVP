import { Injectable, inject } from "@angular/core";
import { type Observable, tap } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import type { Communication, GenerateCommunicationResponse } from "../../../../api/generated/model";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import type { CommunicationDraftForm } from "../assistant.model";

/** Filtri per lo storico comunicazioni (UC-15..UC-18): chiavi opzionali, ignorate se nulle o vuote. */
export interface CommunicationFilters {
  keyword?: string | null;
  tone?: string | null;
  style?: string | null;
  date?: string | null;
}

/**
 * Generazione assistita di comunicazioni HR. La risposta contiene sia la bozza
 * sia lo stato applicativo aggiornato: si rimpiazza lo store con quello
 * autorevole del backend e si restituisce la risposta al componente per
 * popolare anteprima e messaggio di stato.
 */
@Injectable({ providedIn: "root" })
export class AssistantService {
  private readonly api = inject(AlittlebyteMVPAPIService);
  private readonly store = inject(MvpStateStore);

  generate(payload: CommunicationDraftForm): Observable<GenerateCommunicationResponse> {
    return this.api
      .generateMvpCommunication(payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  /**
   * Lo storico e' interamente caricato via `getMvpState` (chiamata generata
   * automaticamente, popolata in MvpStateStore): il backend non espone un
   * endpoint di filtro dedicato, quindi i criteri puliti vengono applicati
   * alle comunicazioni gia' recuperate.
   */
  getFilteredCommunications(filters: CommunicationFilters): Communication[] {
    const cleaned = this.cleanFilters(filters);
    return this.store.history().filter((communication) => this.matchesFilters(communication, cleaned));
  }

  private cleanFilters(filters: CommunicationFilters): CommunicationFilters {
    const cleaned: CommunicationFilters = {};

    (Object.keys(filters) as (keyof CommunicationFilters)[]).forEach((key) => {
      const value = filters[key];

      if (typeof value === "string" && value.trim() !== "") {
        cleaned[key] = value.trim();
      }
    });

    return cleaned;
  }

  private matchesFilters(communication: Communication, filters: CommunicationFilters): boolean {
    if (filters.keyword && !communication.prompt.toLowerCase().includes(filters.keyword.toLowerCase())) {
      return false;
    }

    if (filters.tone && communication.tone !== filters.tone) {
      return false;
    }

    if (filters.style && communication.style !== filters.style) {
      return false;
    }

    if (filters.date && !(communication.createdAt ?? "").startsWith(filters.date)) {
      return false;
    }

    return true;
  }
}
