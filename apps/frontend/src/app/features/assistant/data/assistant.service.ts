import { Injectable, inject } from "@angular/core";
import { type Observable, tap } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import type {
  GenerateCommunicationResponse,
  RateCommunicationRequest,
  RateCommunicationResponse
} from "../../../../api/generated/model";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import type { CommunicationDraftForm } from "../assistant.model";

/**
 * Generazione assistita di comunicazioni HR e valutazione delle bozze.
 * Le risposte aggiornano lo store con lo stato autorevole del backend.
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

  rate(communicationId: number, payload: RateCommunicationRequest): Observable<RateCommunicationResponse> {
    return this.api
      .rateMvpCommunication(communicationId, payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }
}
