import { Injectable, inject } from "@angular/core";
import { Observable, tap } from "rxjs";
import { AlittlebytePoCAPIService } from "../../../../api/generated/poc-api";
import type {
  DeleteDocumentResponse,
  PocState,
  SubDocument,
  UpdateExtractedDataRequest,
  UpdateSubDocumentReviewResponse
} from "../../../../api/generated/model";
import { PocStateStore } from "../../../core/state/poc-state.store";
import { getSubDocumentNumericId } from "../../../shared/util/formatters";

/** Stato dell'anteprima PDF di un sotto-documento. */
export type DocumentPreviewStatus = "idle" | "loading" | "available" | "unavailable" | "unreachable";

/**
 * Fase dell'elaborazione, usata dalla barra di progressione a step:
 * - `uploading`  : invio del file in corso (POST non ancora risolto)
 * - `queued`     : upload accettato, workflow in coda
 * - `processing` : OCR/analisi avviata, nessun sotto-documento ancora estratto
 * - `extracting` : sotto-documenti in fase di estrazione/split
 * - `completed`  : elaborazione conclusa
 * - `failed`     : elaborazione non disponibile/fallita
 */
export type DocumentUploadPhase =
  | "uploading"
  | "queued"
  | "processing"
  | "extracting"
  | "completed"
  | "failed";

/** Avanzamento dell'upload+elaborazione di un documento. */
export interface DocumentUploadProgress {
  status: string;
  phase: DocumentUploadPhase;
  receivedDocumentId?: string;
}

/** Payload dell'evento SSE `progress` emesso dal backend. */
interface ProcessingProgressEvent {
  status: "pending" | "processing" | "completed" | "failed";
  subDocuments: number;
}

/**
 * Pipeline documentale del Co-Pilot: upload con elaborazione asincrona via SSE,
 * revisione/validazione dei dati estratti, eliminazione e verifica anteprima.
 * Tutte le mutazioni rimpiazzano lo store con lo stato autorevole del backend.
 */
@Injectable({ providedIn: "root" })
export class DocumentWorkflowService {
  private readonly api = inject(AlittlebytePoCAPIService);
  private readonly store = inject(PocStateStore);

  /**
   * Carica il documento e segue lo stream di elaborazione (Server-Sent Events).
   * La prima emissione corrisponde alla conferma dell'upload (POST risolto), le
   * successive agli eventi di elaborazione. Lo stream viene chiuso alla
   * conclusione o all'annullamento della sottoscrizione (nessun leak di
   * connessioni). Nessun fallback automatico: in caso di errore lo stato
   * documentale viene solo ricaricato per riflettere la situazione reale.
   */
  upload(file: File): Observable<DocumentUploadProgress> {
    return new Observable<DocumentUploadProgress>((observer) => {
      let eventSource: EventSource | null = null;

      const subscription = this.api.uploadPocDocument({ document: file }).subscribe({
        next: (response) => {
          observer.next({ status: response.message, phase: "queued" });

          eventSource = new EventSource(response.streamUrl);

          eventSource.addEventListener("progress", (event) => {
            const progress = JSON.parse((event as MessageEvent).data) as ProcessingProgressEvent;
            observer.next({
              status: progressStatusLabel(progress),
              phase: progressPhase(progress)
            });
          });

          eventSource.addEventListener("document", (event) => {
            const document = JSON.parse((event as MessageEvent).data) as SubDocument;
            this.store.upsertDocument(document);
            observer.next({
              status: "Estrazione dati dai sotto-documenti in corso.",
              phase: "extracting",
              receivedDocumentId: document.id
            });
          });

          eventSource.addEventListener("done", (event) => {
            const payload = JSON.parse((event as MessageEvent).data) as { state?: PocState };

            if (payload.state) {
              this.store.setState(payload.state);
            }

            observer.next({ status: "Elaborazione completata.", phase: "completed" });
            eventSource?.close();
            observer.complete();
          });

          eventSource.addEventListener("error", () => {
            observer.next({
              status: "Elaborazione non disponibile. Controlla lo stato del documento.",
              phase: "failed"
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

  deleteSubDocument(documentId: string): Observable<DeleteDocumentResponse> {
    return this.api
      .deletePocSubDocument(getSubDocumentNumericId(documentId))
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  saveExtractedData(
    documentId: string,
    payload: UpdateExtractedDataRequest
  ): Observable<UpdateSubDocumentReviewResponse> {
    return this.api
      .updatePocSubDocumentExtractedData(getSubDocumentNumericId(documentId), payload)
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  markReviewed(documentId: string): Observable<UpdateSubDocumentReviewResponse> {
    return this.api
      .reviewPocSubDocument(getSubDocumentNumericId(documentId))
      .pipe(tap((response) => this.store.setState(response.state)));
  }

  /**
   * Verifica il content-type dell'anteprima prima di montarne l'iframe:
   * l'endpoint puo' rispondere col PDF (200), 404 se assente o 503 JSON se lo
   * storage non e' raggiungibile.
   */
  previewStatus(previewUrl: string): Observable<DocumentPreviewStatus> {
    return new Observable<DocumentPreviewStatus>((observer) => {
      let cancelled = false;
      observer.next("loading");

      fetch(previewUrl, { credentials: "include" })
        .then((response) => {
          if (cancelled) {
            return;
          }

          const contentType = response.headers.get("content-type") ?? "";

          if (response.ok && contentType.includes("application/pdf")) {
            observer.next("available");
          } else if (response.status === 503) {
            observer.next("unreachable");
          } else {
            observer.next("unavailable");
          }

          observer.complete();
        })
        .catch(() => {
          if (!cancelled) {
            observer.next("unreachable");
            observer.complete();
          }
        });

      return () => {
        cancelled = true;
      };
    });
  }
}

/** Traduce l'evento `progress` del backend nella fase della barra a step. */
function progressPhase(progress: ProcessingProgressEvent): DocumentUploadPhase {
  switch (progress.status) {
    case "completed":
      return "completed";
    case "failed":
      return "failed";
    case "processing":
      return progress.subDocuments > 0 ? "extracting" : "processing";
    default:
      return "queued";
  }
}

/** Etichetta leggibile per lo stato testuale mostrato sotto la barra. */
function progressStatusLabel(progress: ProcessingProgressEvent): string {
  switch (progressPhase(progress)) {
    case "extracting":
      return "Estrazione dati dai sotto-documenti in corso.";
    case "processing":
      return "Analisi OCR del documento in corso.";
    case "completed":
      return "Elaborazione completata.";
    case "failed":
      return "Elaborazione non disponibile. Controlla lo stato del documento.";
    default:
      return "Documento in coda di elaborazione.";
  }
}
