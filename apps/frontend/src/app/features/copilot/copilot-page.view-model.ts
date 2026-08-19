import { type Signal, type WritableSignal, computed, signal } from "@angular/core";
import { type Subscription, finalize } from "rxjs";
import type {
  SubDocument,
  UpdateExtractedDataRequest,
  UpdateSendMessageRequest
} from "../../../api/generated/model";
import { extractFieldErrors, getApiErrorMessage } from "../../core/errors/api-error";
import type { CompositionSlice } from "../../shared/components/metric-composition/metric-composition";
import type { MetricPresentation } from "../../shared/components/metrics-panel/metrics-panel";

/** Metriche che il pannello non ripete perché sono le parti della ripartizione. */
const MODULE_COMPOSITION_KEYS = ["copilot.needs_review", "copilot.validated", "copilot.quarantined"];
import { MvpStateStore } from "../../core/state/mvp-state.store";
import type { DocumentUploadRequest } from "./components/document-upload-panel";
import {
  DocumentWorkflowService,
  type DocumentFilters,
  type DocumentUploadPhase
} from "./data/document-workflow.service";

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

  readonly error: Signal<string | null> = computed(() => this.store.error());
  readonly loading: Signal<boolean> = computed(() => this.store.loading());
  /**
   * Le metriche descrittive del modulo. `needs_review`, `validated` e
   * `quarantined` sono escluse: qui non servono come conteggi separati, sono
   * la ripartizione mostrata da `reviewComposition`, e in forma di priorità
   * vivono già nella Overview.
   */
  readonly metrics = computed(() =>
    this.store
      .copilotMetrics()
      .filter((metric) => !MODULE_COMPOSITION_KEYS.includes(metric.key))
  );

  /**
   * Forma e parametri di ciascuna scheda. Non tutte le metriche rispondono
   * alla stessa domanda: due sono quote sul totale dei documenti, due sono
   * conteggi di guasti che quasi sempre valgono zero, una e' una misura su
   * una scala con la soglia oltre cui il sistema valida da solo.
   */
  readonly metricsPresentation = computed<Record<string, MetricPresentation>>(() => {
    const documents = this.store.metricEntry("copilot.documents");
    const total = typeof documents?.value === "number" ? documents.value : undefined;

    return {
      "copilot.sub_documents": { kind: "share", outOf: total },
      "copilot.in_progress": { kind: "share", outOf: total },
      "copilot.processing_stuck": {
        kind: "status",
        okLabel: "Nessuna in ritardo",
        issueLabel: "Da sbloccare"
      },
      "copilot.processing_failed": {
        kind: "status",
        okLabel: "Nessun errore",
        issueLabel: "Da ricaricare"
      },
      // La soglia arriva dal contratto: la stabilisce la configurazione del
      // backend, non questa pagina.
      "copilot.ocr_confidence": { kind: "gauge" }
    };
  });

  /**
   * Stati di revisione come partizione dei sotto-documenti.
   *
   * I quattro stati coprono l'intero insieme, quindi la domanda utile non è
   * "quanti sono da verificare" — a cui risponde già la Overview — ma "quanta
   * parte del totale rappresentano".
   */
  readonly reviewComposition = computed<CompositionSlice[]>(() => {
    const metrics = this.store.copilotMetrics();
    const valueOf = (key: string): number => {
      const found = metrics.find((metric) => metric.key === key);

      return typeof found?.value === "number" ? found.value : 0;
    };

    const validated = valueOf("copilot.validated");
    const needsReview = valueOf("copilot.needs_review");
    const quarantined = valueOf("copilot.quarantined");
    const known = validated + needsReview + quarantined;
    const total = valueOf("copilot.sub_documents");

    return [
      { label: "Validati", value: validated },
      { label: "Da verificare", value: needsReview },
      { label: "In quarantena", value: quarantined },
      // I sotto-documenti ancora senza esito: senza questa voce la barra
      // direbbe che tutto è già stato classificato.
      { label: "In elaborazione", value: Math.max(0, total - known) }
    ];
  });

  private searchSubscription: Subscription | null = null;

  constructor(
    private readonly workflow: DocumentWorkflowService,
    private readonly store: MvpStateStore
  ) {}

  setActiveFilters(filters: DocumentFilters): void {
    this.activeFilters.set(filters);
  }

  /**
   * Rilettura dello storico documenti: stessa forma di upload/deleteDocument/...,
   * non solo un setter. Annulla la ricerca precedente ancora in volo prima di
   * avviarne una nuova, cosi' una risposta arrivata in ritardo non sovrascrive
   * mai un risultato piu' recente.
   */
  reload(): void {
    this.searchSubscription?.unsubscribe();
    this.searchSubscription = this.workflow.searchDocuments(this.activeFilters()).subscribe({
      next: (documents) => this.setFilteredDocuments(documents),
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

  private setFilteredDocuments(documents: SubDocument[]): void {
    this.filteredDocuments.set(documents);
    this.documentsError.set(null);
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
