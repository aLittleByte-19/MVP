import { type Signal, type WritableSignal, computed, signal } from "@angular/core";
import { type Subscription, finalize } from "rxjs";
import type {
  SubDocument,
  UpdateExtractedDataRequest,
  UpdateSendMessageRequest
} from "../../../api/generated/model";
import { extractFieldErrors, getApiErrorMessage } from "../../core/errors/api-error";
import type { MetricPresentation } from "../../shared/components/metrics-panel/metrics-panel";

/**
 * Metriche che il pannello non mostra come schede a sé.
 *
 * `needs_review`, `validated` e `quarantined` sono le parti della ripartizione
 * disegnata dalla scheda degli esiti, e come priorità vivono nella Overview;
 * `sub_documents` è il totale su cui si misurano le quote e come conteggio
 * isolato non aggiunge nulla.
 */
const HIDDEN_KEYS = [
  "copilot.needs_review",
  "copilot.validated",
  "copilot.quarantined",
  "copilot.sub_documents"
];
import { MvpStateStore } from "../../core/state/mvp-state.store";
import type { DocumentUploadRequest } from "./components/document-upload-panel";
import {
  DocumentWorkflowService,
  type DocumentFilters,
  type DocumentPage,
  type DocumentUploadPhase
} from "./data/document-workflow.service";

/**
 * Righe per pagina dello storico. Dieci: la tabella resta leggibile senza
 * scorrere, e il dettaglio del documento sotto resta raggiungibile.
 */
const DOCUMENTS_PER_PAGE = 10;

/**
 * ViewModel (Presentation Model, Fowler) del Co-Pilot documentale: non tocca
 * il DOM né l'infrastruttura di rendering di Angular (niente `@Component`,
 * `inject()`, decoratori, injection context) — le dipendenze arrivano dal
 * costruttore, non da `inject()`, quindi la classe è istanziabile con `new`
 * e testabile senza `TestBed`. Usa `signal`/`computed` come primitiva
 * reattiva di piattaforma (analogo a `INotifyPropertyChanged` in WPF), non
 * come parte del motore di rendering — la distinzione conta: è quello che
 * rende la classe testabile senza avviare Angular, non "zero import da
 * `@angular/core`".
 *
 * `CopilotPage` (la View) resta l'unico punto accoppiato ad Angular: si
 * procura le dipendenze con `inject()` e costruisce questa istanza. Il
 * template legge solo `vm.*` — anche `error`/`loading`/`metrics`, pass-through
 * sullo store condiviso, non `store.*` direttamente.
 *
 * `effect()`/`takeUntilDestroyed()` restano nella View perché richiedono un
 * injection context che questa classe non ha per costruzione, ma l'effect si
 * limita a leggere i segnali sorgente e chiamare `reload()`: la chiamata di
 * ricerca vera e propria vive qui, come per ogni altra azione del VM.
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

  /**
   * Paginazione dello storico. Prima esisteva solo lato backend, che rispondeva
   * con i primi quaranta risultati mentre la vista ne scartava il totale: oltre
   * il quarantesimo i documenti sparivano senza che nulla lo segnalasse.
   */
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
   * Forma e ingombro di ciascuna scheda del pannello.
   *
   * Sedici celle su quattro righe: sette schede larghe a coppie e, a chiudere
   * l'ultima riga, i due verdetti stretti. Le larghe sono quelle che portano
   * un asse o una legenda da leggere; un verdetto di tre parole non ne ha
   * bisogno.
   */
  readonly metricsPresentation = computed<Record<string, MetricPresentation>>(() => ({
    "copilot.documents": { kind: "trend", span: 2 },
    "copilot.ocr_confidence": { kind: "gauge", span: 2 },
    "copilot.review_breakdown": { kind: "breakdown", span: 2 },
    "copilot.download_breakdown": { kind: "breakdown", span: 2 },
    "copilot.field_confidence": { kind: "breakdown", span: 2 },
    "copilot.processing_seconds": { kind: "phases", span: 2 },
    "copilot.duration": { kind: "distribution", span: 2 },
    "copilot.processing_failed": {
      kind: "status",
      okLabel: "Nessun errore",
      issueLabel: "da ricaricare"
    },
    "copilot.processing_stuck": {
      kind: "status",
      okLabel: "Nessuna in ritardo",
      issueLabel: "da sbloccare"
    }
  }));

  private searchSubscription: Subscription | null = null;

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
   * Rilettura dello storico documenti: stessa forma di upload/deleteDocument/...,
   * non solo un setter. Annulla la ricerca precedente ancora in volo prima di
   * avviarne una nuova, cosi' una risposta arrivata in ritardo non sovrascrive
   * mai un risultato piu' recente.
   */
  reload(): void {
    this.searchSubscription?.unsubscribe();
    this.searchSubscription = this.workflow
      .searchDocuments(this.activeFilters(), this.currentPage(), this.pageSize)
      .subscribe({
        next: (page) => this.setFilteredDocuments(page),
        error: (error: unknown) => this.handleDocumentsError(error)
      });
  }

  /**
   * Ricarica lo stato condiviso, che è ciò che il pulsante "Riprova"
   * dell'errore di pagina deve rifare. Distinto da `reload()`, che rilegge il
   * storico documenti: confonderli lascerebbe lo stato globale in errore
   * pur avendo ricaricato l'elenco.
   */
  reloadState(): void {
    this.store.reload();
  }

  /**
   * Chiamato dalla View alla distruzione del componente (via `DestroyRef`):
   * annulla solo la ricerca in lettura, non le azioni di scrittura (upload,
   * delete, ...) — quelle devono completare lato server anche se l'utente
   * ha gia' navigato altrove, esattamente come si aspetta di vederle
   * riflesse al ritorno sulla pagina.
   */
  destroy(): void {
    this.searchSubscription?.unsubscribe();
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
