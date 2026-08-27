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

/**
 * Metriche che il pannello non mostra come schede a sé.
 *
 * `validated` e `quarantined` sono le parti della ripartizione disegnata
 * dalla scheda degli esiti, e come priorità vivono nella Overview;
 * `sub_documents` è il totale su cui si misurano le quote e come conteggio
 * isolato non aggiunge nulla. `ocr_confidence` non risponde a nessun UC-56,
 * ma resta nel contratto perché la Overview la legge (`copilotQuality`).
 */
const HIDDEN_KEYS = [
  "copilot.ocr_confidence",
  "copilot.validated",
  "copilot.quarantined",
  "copilot.sub_documents"
];

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
 * injection context che questa classe non ha per costruzione, ma gli effect
 * si limitano a leggere i segnali sorgente e chiamare `reload()`/
 * `loadPreviewStatus()`: la chiamata vera e propria — inclusa la fetch di
 * anteprima, che altrimenti finirebbe in un componente figlio — vive qui,
 * come per ogni altra azione del VM.
 */
export class CopilotPageViewModel {
  readonly selectedDocumentId: WritableSignal<string | null> = signal(null);
  readonly selectedDocument: Signal<SubDocument | null> = computed(() => {
    const documents = this.filteredDocuments();
    const selectedId = this.selectedDocumentId();

    return documents.find((documentItem) => documentItem.id === selectedId) ?? documents[0] ?? null;
  });
  readonly selectedDocumentIdForList: Signal<string | null> = computed(() => this.selectedDocument()?.id ?? null);

  /**
   * Raggiungibilità dell'anteprima del documento selezionato: verifica il
   * content-type prima che il dettaglio monti l'iframe (l'endpoint può
   * rispondere col PDF, 404 se assente o 503 se lo storage non è
   * raggiungibile). Segue la stessa selezione, non un elenco a parte:
   * `loadPreviewStatus()` la richiede di nuovo a ogni cambio di
   * `selectedDocument`, come `reload()` per lo storico.
   */
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
   * Forma e ingombro di ciascuna scheda del pannello: le tre schede a quota
   * sono larghe, perché portano un anello e una legenda da leggere; le due
   * strette (un conteggio, una durata media) restano a una cella. Il
   * conteggio delle celle non basta da solo: la griglia CSS riempie le righe
   * nell'ordine con cui il backend le manda (vedi `metrics` qui sopra), senza
   * ricomporle per colmare i vuoti — due strette (due celle) seguite da tre
   * larghe (sei celle) chiudono ordinatamente due righe da quattro; un ordine
   * diverso, anche a parità di celle totali, può lasciare una cella vuota a
   * metà riga. L'ordine delle schede lo decide `MvpStateService::copilotState`
   * proprio per questo.
   */
  readonly metricsPresentation = computed<Record<string, MetricPresentation>>(() => ({
    "copilot.documents": { kind: "trend" },
    "copilot.processing_seconds": { kind: "trend" },
    // UC-56.2: percentuale di classificazioni corrette senza intervento
    // manuale. Il denominatore e' solo chi e' arrivato a una validazione
    // (automatica o manuale): chi e' ancora da revisionare o in quarantena
    // non e' incluso, ne' nel resto ne' nel totale — non e' una
    // classificazione conclusa (backend: MvpStateService::copilotState).
    // Il resto e' quindi solo chi e' stato validato a mano: gia' risolto,
    // non un'urgenza.
    "copilot.auto_classified": {
      kind: "share",
      span: 2,
      restTone: "neutral",
      restNoun: "validati a mano"
    },
    // UC-56.3: conteggio e percentuale sotto la soglia di confidenza. Il
    // resto include anche i documenti in quarantena, che non hanno mai
    // ricevuto un punteggio di confidenza da confrontare con la soglia
    // (ExtractSubDocumentFieldsService li mette in quarantena prima del
    // confronto): "sopra soglia" sarebbe falso per loro.
    "copilot.needs_review": {
      kind: "share",
      span: 2,
      restTone: "neutral",
      restNoun: "non da revisionare"
    },
    // UC-56.4: destinatario ancora quello letto dall'AI, mai corretto a mano.
    // Il resto non e' sempre "corretto a mano": include anche i casi in cui
    // l'AI non aveva riconosciuto nulla da confermare (vedi il guard su
    // aiRecognizedSomething in recipientAutoMatchMetric).
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
   * Rilettura dello storico documenti: stessa forma di upload/deleteDocument/...,
   * non solo un setter. Annulla la ricerca precedente ancora in volo prima di
   * avviarne una nuova, cosi' una risposta arrivata in ritardo non sovrascrive
   * mai un risultato piu' recente.
   *
   * Il corpo gira dentro `untracked()`: `CopilotPage` chiama questo metodo da
   * un `effect()` che legge solo `store.documents()`/`vm.activeFilters()`
   * apposta, per non far ripartire la lettura due volte ad ogni cambio
   * pagina (`goToPage()` la richiama gia' da se') — ma senza `untracked()`
   * la lettura di `currentPage()` qui sotto diventerebbe comunque una
   * dipendenza nascosta di quell'effect, vanificando l'esclusione voluta.
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

  /**
   * Richiede di nuovo la raggiungibilità dell'anteprima: stessa forma di
   * `reload()`, sul documento selezionato invece che sullo storico. Annulla
   * la richiesta precedente ancora in volo, così una risposta tardiva su un
   * documento ormai deselezionato non sovrascrive lo stato di quello corrente.
   */
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
