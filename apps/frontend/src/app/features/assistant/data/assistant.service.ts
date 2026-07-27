import { Injectable, inject } from "@angular/core";
import { Observable, tap } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import type { Communication, CommunicationMutationResponse, MvpState } from "../../../../api/generated/model";
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

/**
 * Generazione assistita di comunicazioni HR. La richiesta viene accettata subito
 * e la pipeline lavora in modo asincrono: il testo arriva per primo, la
 * copertina dopo. Una copertina non disponibile non invalida la comunicazione,
 * viene solo segnalata. Nessun fallback automatico: in caso di errore lo stato
 * viene ricaricato per riflettere la situazione reale.
 */
@Injectable({ providedIn: "root" })
export class AssistantService {
  private readonly api = inject(AlittlebyteMVPAPIService);
  private readonly store = inject(MvpStateStore);

  generate(payload: CommunicationDraftForm): Observable<CommunicationGenerationProgress> {
    return new Observable<CommunicationGenerationProgress>((observer) => {
      let eventSource: EventSource | null = null;

      const subscription = this.api.generateMvpCommunication(payload).subscribe({
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
