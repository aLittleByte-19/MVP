import { Injectable, inject } from "@angular/core";
import { type Observable, tap } from "rxjs";
import { AlittlebytePoCAPIService } from "../../../../api/generated/poc-api";
import type { GenerateCommunicationResponse } from "../../../../api/generated/model";
import { PocStateStore } from "../../../core/state/poc-state.store";
import type { CommunicationDraftForm } from "../assistant.model";

/**
 * Generazione assistita di comunicazioni HR. La risposta contiene sia la bozza
 * sia lo stato applicativo aggiornato: si rimpiazza lo store con quello
 * autorevole del backend e si restituisce la risposta al componente per
 * popolare anteprima e messaggio di stato.
 */
@Injectable({ providedIn: "root" })
export class AssistantService {
  private readonly api = inject(AlittlebytePoCAPIService);
  private readonly store = inject(PocStateStore);

  generate(payload: CommunicationDraftForm): Observable<GenerateCommunicationResponse> {
    return this.api
      .generatePocCommunication(payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }
}
