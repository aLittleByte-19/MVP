import { type Signal, type WritableSignal, computed, signal } from "@angular/core";
import { finalize } from "rxjs";
import type {
  SubDocument,
  UpdateExtractedDataRequest,
  UpdateSendMessageRequest
} from "../../../api/generated/model";
import { extractFieldErrors, getApiErrorMessage } from "../../core/errors/api-error";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import type { DocumentUploadRequest } from "./components/document-upload-panel";
import {
  DocumentWorkflowService,
  type DocumentFilters,
  type DocumentUploadPhase
} from "./data/document-workflow.service";

/**
 * ViewModel puro del Co-Pilot documentale (MVVM in senso classico): nessun
 * riferimento ad Angular (niente `inject()`, decoratori, injection
 * context) — le dipendenze arrivano dal costruttore, non da `inject()`,
 * quindi la classe è istanziabile con `new` e testabile senza `TestBed`.
 * `CopilotPage` (la View) resta l'unico punto accoppiato ad Angular: si
 * procura le dipendenze con `inject()` e costruisce questa istanza.
 *
 * `effect()`/`takeUntilDestroyed()` restano nella View perché richiedono un
 * injection context che questa classe non ha per costruzione — vedi
 * `setFilteredDocuments()`/`handleDocumentsError()`, che ricevono il
 * risultato già calcolato invece di gestire loro stessi la sottoscrizione.
 */
export class CopilotPageViewModel {
  readonly selectedDocumentId: WritableSignal<string | null> = signal(null);
  readonly selectedDocument: Signal<SubDocument | null> = computed(() => {
    const documents = this.filteredDocuments();
    const selectedId = this.selectedDocumentId();

    return documents.find((documentItem) => documentItem.id === selectedId) ?? documents[0] ?? null;
  });
  readonly selectedDocumentIdForList: Signal<string | null> = computed(() => this.selectedDocument()?.id ?? null);
  readonly isUploading = signal(false);
  readonly uploadStatus = signal("Nessun caricamento in corso.");
  readonly uploadPhase: WritableSignal<DocumentUploadPhase | null> = signal(null);
  readonly isDeleting = signal(false);
  readonly isSavingReview = signal(false);
  readonly reviewError: WritableSignal<string | null> = signal(null);
  readonly isSavingSendMessage = signal(false);
  readonly sendMessageError: WritableSignal<string | null> = signal(null);
  readonly reviewFieldErrors: WritableSignal<Record<string, string> | null> = signal(null);

  readonly activeFilters: WritableSignal<DocumentFilters> = signal({});
  readonly hasActiveFilters: Signal<boolean> = computed(() => Object.keys(this.activeFilters()).length > 0);
  readonly filteredDocuments: WritableSignal<SubDocument[]> = signal([]);
  readonly documentsError: WritableSignal<string | null> = signal(null);

  constructor(
    private readonly workflow: DocumentWorkflowService,
    private readonly store: MvpStateStore
  ) {}

  setActiveFilters(filters: DocumentFilters): void {
    this.activeFilters.set(filters);
  }

  setFilteredDocuments(documents: SubDocument[]): void {
    this.filteredDocuments.set(documents);
    this.documentsError.set(null);
  }

  handleDocumentsError(error: unknown): void {
    this.documentsError.set(getApiErrorMessage(error, "Storico documenti non disponibile."));
  }

  selectDocument(documentId: string | null): void {
    this.selectedDocumentId.set(documentId);
  }

  upload(request: DocumentUploadRequest): void {
    this.isUploading.set(true);
    this.uploadPhase.set("uploading");
    this.uploadStatus.set("Caricamento documento in corso.");

    this.workflow
      .upload(request.file, request.metadata)
      .pipe(finalize(() => this.isUploading.set(false)))
      .subscribe({
        next: (progress) => {
          this.uploadStatus.set(progress.status);
          this.uploadPhase.set(progress.phase);

          if (progress.receivedDocumentId) {
            this.selectedDocumentId.set(progress.receivedDocumentId);
          }
        },
        error: (error: unknown) => {
          this.uploadPhase.set("failed");
          this.uploadStatus.set(getApiErrorMessage(error, "Upload non disponibile."));
          this.store.reload();
        }
      });
  }

  deleteDocument(documentId: string): void {
    this.isDeleting.set(true);

    this.workflow
      .deleteSubDocument(documentId)
      .pipe(finalize(() => this.isDeleting.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(null),
        error: (error: unknown) => {
          this.reviewError.set(getApiErrorMessage(error, "Eliminazione non disponibile."));
        }
      });
  }

  markReviewed(documentId: string): void {
    this.reviewError.set(null);
    this.isSavingReview.set(true);

    this.workflow
      .markReviewed(documentId)
      .pipe(finalize(() => this.isSavingReview.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(documentId),
        error: (error: unknown) => {
          this.reviewError.set(getApiErrorMessage(error, "Validazione non disponibile."));
        }
      });
  }

  saveReview(event: { documentId: string; payload: UpdateExtractedDataRequest }): void {
    this.reviewError.set(null);
    this.reviewFieldErrors.set(null);
    this.isSavingReview.set(true);

    this.workflow
      .saveExtractedData(event.documentId, event.payload)
      .pipe(finalize(() => this.isSavingReview.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(event.documentId),
        error: (error: unknown) => {
          this.reviewError.set(getApiErrorMessage(error, "Salvataggio revisione non disponibile."));
          this.reviewFieldErrors.set(extractFieldErrors(error));
        }
      });
  }

  saveSendMessage(event: { documentId: string; payload: UpdateSendMessageRequest }): void {
    this.sendMessageError.set(null);
    this.isSavingSendMessage.set(true);

    this.workflow
      .saveSendMessage(event.documentId, event.payload)
      .pipe(finalize(() => this.isSavingSendMessage.set(false)))
      .subscribe({
        next: () => this.selectedDocumentId.set(event.documentId),
        error: (error: unknown) => {
          this.sendMessageError.set(getApiErrorMessage(error, "Salvataggio messaggio di invio non disponibile."));
        }
      });
  }
}
