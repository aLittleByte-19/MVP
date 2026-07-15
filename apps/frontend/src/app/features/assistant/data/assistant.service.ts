import { Injectable, inject } from "@angular/core";
import { type Observable, tap } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import type { GenerateCommunicationResponse } from "../../../../api/generated/model";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import type { CommunicationDraftForm } from "../assistant.model";

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
}
