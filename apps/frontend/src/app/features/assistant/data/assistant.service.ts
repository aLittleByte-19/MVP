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
  UpdateCommunicationFavoriteResponse,
  UpdateCommunicationRequest,
  UpdateCommunicationResponse
} from "../../../../api/generated/model";
import { SseClient } from "../../../core/http/sse-client";
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
 * Generazione assistita di comunicazioni HR e valutazione delle bozze: la
 * pipeline lavora in modo asincrono, il testo arriva per primo, la copertina dopo.
 * Una copertina non disponibile non invalida la comunicazione, viene solo segnalata.
 */
@Injectable({ providedIn: "root" })
export class AssistantService {
  private readonly api = inject(AlittlebyteMVPAPIService);
  private readonly store = inject(MvpStateStore);
  private readonly sse = inject(SseClient);

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
      let closeStream: (() => void) | null = null;

      const subscription = start$.subscribe({
        next: (response) => {
          observer.next({
            status: response.message,
            phase: "queued",
            communicationId: response.communicationId
          });

          closeStream = this.sse.connect(response.streamUrl, {
            onEvent: (event, data) => {
              if (event === "progress") {
                const progress = data as GenerationProgressEvent;
                observer.next({
                  status: progressStatusLabel(progress),
                  phase: progressPhase(progress),
                  communicationId: response.communicationId
                });
                return;
              }

              if (event === "text") {
                const text = data as GenerationTextEvent;
                observer.next({
                  status: "Testo generato. Generazione copertina in corso.",
                  phase: "generating-cover",
                  communicationId: response.communicationId,
                  text
                });
                return;
              }

              if (event === "cover") {
                const cover = data as GenerationCoverEvent;
                observer.next({
                  status: cover.coverStatus === "ready" ? "Copertina generata." : "Copertina non disponibile.",
                  phase: "generating-cover",
                  communicationId: response.communicationId,
                  cover
                });
                return;
              }

              if (event === "still_running") {
                const payload = data as { message?: string };
                observer.next({
                  status: payload.message ?? "Generazione ancora in corso. Lo stato verrà aggiornato.",
                  phase: "still_running",
                  communicationId: response.communicationId
                });
                return;
              }

              if (event === "done") {
                const payload = data as { communication?: Communication; state?: MvpState };

                if (payload.state) {
                  this.store.setState(payload.state);
                }

                observer.next({
                  status: "Bozza generata correttamente.",
                  phase: "completed",
                  communicationId: response.communicationId,
                  communication: payload.communication
                });
                closeStream?.();
                observer.complete();
              }
            },
            onNamedError: (message) => {
              observer.next({
                status: message || "Generazione non disponibile. Controlla lo stato della bozza.",
                phase: "failed",
                communicationId: response.communicationId
              });
              closeStream?.();
              this.store.reload();
              observer.complete();
            },
            onConnectionError: () => {
              closeStream?.();
              this.store.reload();
              observer.complete();
            }
          });
        },
        error: (error: unknown) => observer.error(error)
      });

      return () => {
        subscription.unsubscribe();
        closeStream?.();
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

  /**
   * Rende la bozza visibile nello storico (UC-9). Il salvataggio decide solo
   * cosa compare nell'elenco: la bozza resta modificabile e rigenerabile,
   * solo lo scarto ne blocca il contenuto.
   */
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

  /** Storico filtrato (UC-15..UC-18): solo le bozze salvate esplicitamente, come `state.assistant.history`. */
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

  favorite(communicationId: number): Observable<UpdateCommunicationFavoriteResponse> {
    return this.api
      .favoriteMvpCommunication(communicationId)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  unfavorite(communicationId: number): Observable<UpdateCommunicationFavoriteResponse> {
    return this.api
      .unfavoriteMvpCommunication(communicationId)
      .pipe(tap((response) => this.store.setState(response.state)));
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
