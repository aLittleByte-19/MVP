import { Injectable, inject } from "@angular/core";
import { Observable, map, tap } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import type {
  Communication,
  CommunicationMutationResponse,
  DeleteDocumentResponse,
  MvpState,
  RateCommunicationRequest,
  RateCommunicationResponse,
  SavePromptConfigurationRequest,
  SavePromptConfigurationResponse,
  StartCommunicationGenerationResponse,
  UpdateCommunicationRequest,
  UpdateCommunicationResponse
} from "../../../../api/generated/model";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import type { CommunicationDraftForm, CommunicationGenerationProgress } from "../assistant.model";

/** Payload dell'evento SSE `progress` emesso dal backend. */
interface GenerationProgressEvent {
  generationStatus: "pending" | "processing" | "completed" | "failed";
  coverStatus: "pending" | "processing" | "ready" | "failed" | "removed";
}

/** Payload dell'evento SSE `text`: titolo e corpo, appena disponibili. */
interface GenerationTextEvent {
  title: string | null;
  body: string | null;
}

/** Payload dell'evento SSE `cover`: esito della copertina. */
interface GenerationCoverEvent {
  coverImageUrl: string | null;
  coverStatus: "ready" | "failed" | "removed";
  coverError: string | null;
}

/** Filtri per lo storico comunicazioni (UC-15..UC-18): chiavi opzionali, ignorate se nulle o vuote. */
export interface CommunicationFilters {
  keyword?: string | null;
  tone?: string | null;
  style?: string | null;
  date?: string | null;
}

/**
 * Generazione assistita di comunicazioni HR e valutazione delle bozze. La
 * richiesta viene accettata subito e la pipeline lavora in modo asincrono: il
 * testo arriva per primo, la copertina dopo. Una copertina non disponibile non
 * invalida la comunicazione, viene solo segnalata. Nessun fallback automatico:
 * in caso di errore lo stato viene ricaricato per riflettere la situazione
 * reale. Le risposte aggiornano lo store con lo stato autorevole del backend.
 */
@Injectable({ providedIn: "root" })
export class AssistantService {
  private readonly api = inject(AlittlebyteMVPAPIService);
  private readonly store = inject(MvpStateStore);

  generate(payload: CommunicationDraftForm): Observable<CommunicationGenerationProgress> {
    return this.trackGeneration(this.api.generateMvpCommunication(payload));
  }

  regenerate(communicationId: number): Observable<CommunicationGenerationProgress> {
    return this.trackGeneration(this.api.regenerateMvpCommunication(communicationId));
  }

  private trackGeneration(
    start$: Observable<StartCommunicationGenerationResponse>
  ): Observable<CommunicationGenerationProgress> {
    return new Observable<CommunicationGenerationProgress>((observer) => {
      let eventSource: EventSource | null = null;

      const subscription = start$.subscribe({
        next: (response) => {
          observer.next({
            status: response.message,
            phase: "queued",
            communicationId: response.communicationId
          });

          eventSource = new EventSource(response.streamUrl);

          eventSource.addEventListener("progress", (event) => {
            const progress = JSON.parse((event as MessageEvent).data) as GenerationProgressEvent;
            observer.next({
              status: progressStatusLabel(progress),
              phase: progressPhase(progress),
              communicationId: response.communicationId
            });
          });

          eventSource.addEventListener("text", (event) => {
            const text = JSON.parse((event as MessageEvent).data) as GenerationTextEvent;
            observer.next({
              status: "Testo generato. Generazione copertina in corso.",
              phase: "generating-cover",
              communicationId: response.communicationId,
              text
            });
          });

          eventSource.addEventListener("cover", (event) => {
            const cover = JSON.parse((event as MessageEvent).data) as GenerationCoverEvent;
            observer.next({
              status: cover.coverStatus === "ready" ? "Copertina generata." : "Copertina non disponibile.",
              phase: "generating-cover",
              communicationId: response.communicationId,
              cover
            });
          });

          eventSource.addEventListener("done", (event) => {
            const payload = JSON.parse((event as MessageEvent).data) as {
              communication?: Communication;
              state?: MvpState;
            };

            if (payload.state) {
              this.store.setState(payload.state);
            }

            observer.next({
              status: "Bozza generata correttamente.",
              phase: "completed",
              communicationId: response.communicationId,
              communication: payload.communication
            });
            eventSource?.close();
            observer.complete();
          });

          eventSource.addEventListener("error", () => {
            observer.next({
              status: "Generazione non disponibile. Controlla lo stato della bozza.",
              phase: "failed",
              communicationId: response.communicationId
            });
            eventSource?.close();
            this.store.reload();
            observer.complete();
          });
        },
        error: (error: unknown) => observer.error(error)
      });

      return () => {
        subscription.unsubscribe();
        eventSource?.close();
      };
    });
  }

  updateCoverImage(communicationId: number, image: File): Observable<CommunicationMutationResponse> {
    return this.api
      .updateMvpCommunicationCoverImage(communicationId, { image })
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  removeCoverImage(communicationId: number): Observable<CommunicationMutationResponse> {
    return this.api
      .removeMvpCommunicationCoverImage(communicationId)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  discard(communicationId: number): Observable<CommunicationMutationResponse> {
    return this.api
      .discardMvpCommunication(communicationId)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  /** Fissa la bozza nello storico (UC-9): da qui non e' piu' modificabile ne' rigenerabile. */
  saveToHistory(communicationId: number): Observable<CommunicationMutationResponse> {
    return this.api
      .saveMvpCommunication(communicationId)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  deleteFromHistory(communicationId: number): Observable<DeleteDocumentResponse> {
    return this.api
      .deleteMvpCommunication(communicationId)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  rate(communicationId: number, payload: RateCommunicationRequest): Observable<RateCommunicationResponse> {
    return this.api
      .rateMvpCommunication(communicationId, payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  update(communicationId: number, payload: UpdateCommunicationRequest): Observable<UpdateCommunicationResponse> {
    return this.api
      .updateMvpCommunication(communicationId, payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  /** Salva la configurazione corrente del prompt nello storico (UC-19). */
  saveConfiguration(payload: SavePromptConfigurationRequest): Observable<SavePromptConfigurationResponse> {
    return this.api
      .saveMvpPromptConfiguration(payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  deleteConfiguration(configurationId: number): Observable<DeleteDocumentResponse> {
    return this.api
      .deleteMvpPromptConfiguration(configurationId)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  /**
   * Storico filtrato (UC-15..UC-18): i criteri viaggiano al backend, che resta
   * l'unica autorita' sui dati. Le bozze scartate restano escluse, come nello
   * storico di `state.assistant.history`.
   */
  searchCommunications(filters: CommunicationFilters): Observable<Communication[]> {
    return this.api
      .listMvpCommunications({
        keyword: filters.keyword ?? undefined,
        tone: filters.tone ?? undefined,
        style: filters.style ?? undefined,
        date: filters.date ?? undefined
      })
      .pipe(map((response) => response.items));
  }

}

function progressPhase(progress: GenerationProgressEvent): CommunicationGenerationProgress["phase"] {
  if (progress.generationStatus === "completed") {
    return "completed";
  }

  if (progress.generationStatus === "failed") {
    return "failed";
  }

  return progress.coverStatus === "processing" ? "generating-cover" : "generating-text";
}

function progressStatusLabel(progress: GenerationProgressEvent): string {
  switch (progress.generationStatus) {
    case "completed":
      return "Bozza generata correttamente.";
    case "failed":
      return "Generazione non disponibile.";
    case "processing":
      return progress.coverStatus === "processing"
        ? "Generazione copertina in corso."
        : "Generazione del testo in corso.";
    default:
      return "Generazione in coda.";
  }
}
