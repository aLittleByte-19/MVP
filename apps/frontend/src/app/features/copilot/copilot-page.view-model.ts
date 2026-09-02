import { type Signal, type WritableSignal, computed, signal, untracked } from "@angular/core";
import { type Subscription, finalize } from "rxjs";
import type {
  SubDocument,
  UpdateExtractedDataRequest,
  UpdateSendMessageRequest
} from "../../../api/generated/model";
import { extractFieldErrors, getApiErrorMessage } from "../../core/errors/api-error";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import type { MetricPresentation } from "../../shared/components/metrics-panel/metrics-panel";
import type { DocumentUploadRequest } from "./components/document-upload-panel";
import {
  DocumentWorkflowService,
  type DocumentFilters,
  type DocumentPage,
  type DocumentPreviewStatus,
  type DocumentUploadPhase
} from "./data/document-workflow.service";

/** Metriche che il pannello non mostra come schede a se': gia' coperte da altre schede o dalla Overview. */
const HIDDEN_KEYS = [
  "copilot.ocr_confidence",
  "copilot.validated",
  "copilot.quarantined",
  "copilot.sub_documents"
];

/** Righe per pagina dello storico: la tabella resta leggibile senza scorrere. */
const DOCUMENTS_PER_PAGE = 10;

/**
 * ViewModel (Presentation Model) del Co-Pilot documentale: nessuna dipendenza da Angular
 * rendering, istanziabile con `new` e testabile senza `TestBed`. `CopilotPage` (la View)
 * costruisce l'istanza e legge solo `vm.*`; gli `effect()` restano nella View (injection
 * context) ma delegano subito a `reload()`/`loadPreviewStatus()` qui.
 */
export class CopilotPageViewModel {
  readonly selectedDocumentId: WritableSignal<string | null> = signal(null);
  readonly selectedDocument: Signal<SubDocument | null> = computed(() => {
    const documents = this.filteredDocuments();
    const selectedId = this.selectedDocumentId();

    return documents.find((documentItem) => documentItem.id === selectedId) ?? documents[0] ?? null;
  });
  readonly selectedDocumentIdForList: Signal<string | null> = computed(() => this.selectedDocument()?.id ?? null);

  /** Raggiungibilita' dell'anteprima: verifica il content-type prima che il dettaglio monti l'iframe. */
  readonly previewStatus: WritableSignal<DocumentPreviewStatus> = signal("idle");
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

  readonly currentPage: WritableSignal<number> = signal(1);
  readonly totalDocuments: WritableSignal<number> = signal(0);
  readonly pageSize = DOCUMENTS_PER_PAGE;
  readonly totalPages: Signal<number> = computed(() =>
    Math.max(1, Math.ceil(this.totalDocuments() / this.pageSize))
  );
  readonly hasPreviousPage: Signal<boolean> = computed(() => this.currentPage() > 1);
  readonly hasNextPage: Signal<boolean> = computed(() => this.currentPage() < this.totalPages());

  readonly error: Signal<string | null> = computed(() => this.store.error());
  readonly loading: Signal<boolean> = computed(() => this.store.loading());
  /** Le metriche descrittive del modulo, meno quelle che il pannello non mostra. */
  readonly metrics = computed(() =>
    this.store
      .copilotMetrics()
      .filter((metric) => !HIDDEN_KEYS.includes(metric.key))
  );

  /**
   * L'ordine qui deve seguire quello con cui il backend manda le metriche
   * (`MvpStateService::copilotState`): la griglia CSS riempie le righe in
   * quell'ordine senza ricomporle, quindi un ordine diverso puo' lasciare
   * una cella vuota a meta' riga.
   */
  readonly metricsPresentation = computed<Record<string, MetricPresentation>>(() => ({
    "copilot.documents": { kind: "trend" },
    "copilot.processing_seconds": { kind: "trend" },
    // UC-56.2: denominatore solo chi ha una validazione conclusa (chi e' ancora
    // da revisionare o in quarantena non conta).
    "copilot.auto_classified": {
      kind: "share",
      span: 2,
      restTone: "neutral",
      restNoun: "validati a mano"
    },
    // UC-56.3: il resto include anche la quarantena, mai arrivata a un punteggio di confidenza.
    "copilot.needs_review": {
      kind: "share",
      span: 2,
      restTone: "neutral",
      restNoun: "non da revisionare"
    },
    // UC-56.4: il resto non e' sempre "corretto a mano" (vedi guard aiRecognizedSomething).
    "copilot.recipient_auto_matched": {
      kind: "share",
      span: 2,
      restTone: "alert",
      restNoun: "non confermati"
    }
  }));

  private searchSubscription: Subscription | null = null;
  private previewSubscription: Subscription | null = null;

  constructor(
    private readonly workflow: DocumentWorkflowService,
    private readonly store: MvpStateStore
  ) {}

  setActiveFilters(filters: DocumentFilters): void {
    this.activeFilters.set(filters);
    // Cambiare filtro rimescola i risultati: restare alla pagina cinque di un
    // elenco che ora ne ha due mostrerebbe una tabella vuota senza spiegazione.
    this.currentPage.set(1);
  }

  /** Va alla pagina indicata, entro i limiti dell'elenco corrente. */
  goToPage(page: number): void {
    const target = Math.min(Math.max(1, page), this.totalPages());

    if (target !== this.currentPage()) {
      this.currentPage.set(target);
      this.reload();
    }
  }

  /**
   * `untracked()` perche' l'effect che chiama questo metodo legge solo `store.documents()`/
   * `activeFilters()` apposta (per non ripartire due volte a ogni cambio pagina); senza,
   * la lettura di `currentPage()` qui sotto diventerebbe una dipendenza nascosta di quell'effect.
   */
  reload(): void {
    untracked(() => {
      this.searchSubscription?.unsubscribe();
      this.searchSubscription = this.workflow
        .searchDocuments(this.activeFilters(), this.currentPage(), this.pageSize)
        .subscribe({
          next: (page) => this.setFilteredDocuments(page),
          error: (error: unknown) => this.handleDocumentsError(error)
        });
    });
  }

  loadPreviewStatus(previewUrl: string | null): void {
    this.previewSubscription?.unsubscribe();

    if (previewUrl === null) {
      this.previewStatus.set("idle");
      return;
    }

    // Il primo evento emesso e' gia' "loading" (vedi DocumentWorkflowService.previewStatus).
    this.previewSubscription = this.workflow
      .previewStatus(previewUrl)
      .subscribe((status) => this.previewStatus.set(status));
  }

  /** Distinto da `reload()`: ricarica lo stato globale (pulsante "Riprova"), non lo storico documenti. */
  reloadState(): void {
    this.store.reload();
  }

  /** Annulla solo la ricerca in lettura: le scritture in corso devono completare anche fuori pagina. */
  destroy(): void {
    this.searchSubscription?.unsubscribe();
    this.previewSubscription?.unsubscribe();
  }

  private setFilteredDocuments(page: DocumentPage): void {
    this.filteredDocuments.set(page.items);
    this.totalDocuments.set(page.total);
    this.documentsError.set(null);

    // L'ultima pagina puo' svuotarsi mentre la si guarda, per esempio dopo
    // un'eliminazione: si torna a quella prima invece di mostrare il vuoto.
    if (page.items.length === 0 && this.currentPage() > 1) {
      this.goToPage(this.currentPage() - 1);
    }
  }

  private handleDocumentsError(error: unknown): void {
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
