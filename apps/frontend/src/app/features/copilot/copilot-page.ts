import { ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, signal } from "@angular/core";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { ReactiveFormsModule } from "@angular/forms";
import { debounceTime, distinctUntilChanged, finalize } from "rxjs";
import {
  SubDocumentSendStatus,
  type SubDocument,
  type UpdateExtractedDataRequest,
  type UpdateSendMessageRequest
} from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { extractFieldErrors, getApiErrorMessage } from "../../core/errors/api-error";
import { ButtonComponent } from "../../shared/components/button/button";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { MetricsPanelComponent } from "../../shared/components/metrics-panel/metrics-panel";
import { SectionComponent } from "../../layout/section/section";
import {
  DocumentWorkflowService,
  type DocumentFilters,
  type DocumentUploadPhase
} from "./data/document-workflow.service";
import { createDocumentFilterForm, toDocumentFilters } from "./data/document-filters";
import { DocumentListComponent } from "./components/document-list";
import { DocumentUploadPanelComponent, type DocumentUploadRequest } from "./components/document-upload-panel";
import { SubDocumentListComponent } from "./components/sub-document-list";

const MONTHS = [
  { value: 1, label: "Gennaio" },
  { value: 2, label: "Febbraio" },
  { value: 3, label: "Marzo" },
  { value: 4, label: "Aprile" },
  { value: 5, label: "Maggio" },
  { value: 6, label: "Giugno" },
  { value: 7, label: "Luglio" },
  { value: 8, label: "Agosto" },
  { value: 9, label: "Settembre" },
  { value: 10, label: "Ottobre" },
  { value: 11, label: "Novembre" },
  { value: 12, label: "Dicembre" }
];

@Component({
  selector: "mvp-copilot-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    DocumentListComponent,
    DocumentUploadPanelComponent,
    ErrorStateComponent,
    MetricsPanelComponent,
    ReactiveFormsModule,
    SectionComponent,
    SubDocumentListComponent
  ],
  template: `
    <section class="view" aria-label="AI Co-Pilot per i CdL">
      @if (store.error(); as error) {
        <mvp-error-state [message]="error" />
      }

      <mvp-document-upload-panel
        [isUploading]="isUploading()"
        [status]="uploadStatus()"
        [phase]="uploadPhase()"
        (upload)="upload($event)"
      />

      <mvp-section id="copilot-documents" title="Storico documenti analizzati">
        <span actions>{{ filteredDocuments().length }} record</span>

        @if (documentsError(); as error) {
          <mvp-error-state [message]="error" />
        }

        <form class="filters" [formGroup]="filterForm" aria-label="Filtra storico documenti">
          <label class="field" for="filter-search">
            <span>Nome, cognome o azienda</span>
            <input id="filter-search" type="text" formControlName="search" placeholder="Cerca nello storico..." />
          </label>
          <label class="field" for="filter-send-status">
            <span>Stato invio</span>
            <select id="filter-send-status" formControlName="sendStatus">
              <option value="">Tutti</option>
              <option [value]="sendStatuses.sent">Inviato</option>
              <option [value]="sendStatuses.pending">Non inviato</option>
            </select>
          </label>
          <label class="field" for="filter-confidence-criterion">
            <span>Confidenza</span>
            <select id="filter-confidence-criterion" formControlName="confidenceCriterion">
              <option value="below">Minore di</option>
              <option value="above">Maggiore di</option>
            </select>
          </label>
          <label class="field" for="filter-confidence-threshold">
            <span>Soglia (%)</span>
            <input
              id="filter-confidence-threshold"
              type="number"
              min="0"
              max="100"
              formControlName="confidenceThreshold"
              placeholder="es. 80"
            />
          </label>
          <label class="field" for="filter-month">
            <span>Mese</span>
            <select id="filter-month" formControlName="month">
              <option value="">Tutti i mesi</option>
              @for (month of months; track month.value) {
                <option [value]="month.value">{{ month.label }}</option>
              }
            </select>
          </label>
          <label class="field" for="filter-year">
            <span>Anno</span>
            <input id="filter-year" type="number" formControlName="year" placeholder="es. 2026" />
          </label>
          <button mvpButton variant="secondary" type="button" (click)="resetFilters()">Azzera filtri</button>
        </form>

        <mvp-document-list
          [documents]="filteredDocuments()"
          [selectedDocumentId]="selectedDocumentIdForList()"
          [emptyMessage]="
            hasActiveFilters()
              ? 'Nessun documento corrisponde ai filtri selezionati.'
              : 'I documenti caricati compariranno qui.'
          "
          (selectDocument)="selectDocument($event)"
        />
      </mvp-section>

      <mvp-sub-document-list
        [documentItem]="selectedDocument()"
        [isDeleting]="isDeleting()"
        [isSavingReview]="isSavingReview()"
        [reviewError]="reviewError()"
        [isSavingSendMessage]="isSavingSendMessage()"
        [sendMessageError]="sendMessageError()"
        [fieldErrors]="reviewFieldErrors()"
        (deleteDocument)="deleteDocument($event)"
        (markReviewed)="markReviewed($event)"
        (saveReviewRequested)="saveReview($event)"
        (saveSendMessageRequested)="saveSendMessage($event)"
      />

      <mvp-section id="copilot-metrics" title="Qualità e performance OCR">
        <mvp-metrics-panel [isLoading]="store.loading()" [metrics]="store.copilotMetrics()" />
      </mvp-section>
    </section>
  `,
  styleUrls: ["../overview/overview-page.css"],
  styles: [
    `
    .filters {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr)) auto;
      gap: var(--mvp-space-3);
      align-items: end;
      margin-bottom: var(--mvp-space-4);
    }

    .field {
      display: grid;
      gap: var(--mvp-space-2);
      color: var(--mvp-text);
      font-weight: 700;
    }

    .field span {
      font-size: var(--mvp-font-sm);
    }

    .field input,
    .field select {
      width: 100%;
      min-height: 42px;
      padding: 0 var(--mvp-space-3);
      border: 1px solid var(--mvp-border-strong);
      border-radius: var(--mvp-radius);
      background: var(--mvp-surface-muted);
      color: var(--mvp-text);
      font: inherit;
    }

    @media (max-width: 900px) {
      .filters {
        grid-template-columns: 1fr;
      }
    }
    `
  ]
})
export class CopilotPage {
  protected readonly store = inject(MvpStateStore);
  protected readonly documents = this.store.documents;
  protected readonly selectedDocumentId = signal<string | null>(null);
  // La selezione si risolve sulla stessa lista che l'utente vede: risolverla
  // sullo store (finestra da 40 alimentata da /api/v1/state) aprirebbe il
  // documento sbagliato per ogni risultato filtrato fuori da quella finestra.
  protected readonly selectedDocument = computed(() => {
    const documents = this.filteredDocuments();
    const selectedId = this.selectedDocumentId();

    return documents.find((documentItem) => documentItem.id === selectedId) ?? documents[0] ?? null;
  });
  protected readonly selectedDocumentIdForList = computed(() => this.selectedDocument()?.id ?? null);
  protected readonly isUploading = signal(false);
  protected readonly uploadStatus = signal("Nessun caricamento in corso.");
  protected readonly uploadPhase = signal<DocumentUploadPhase | null>(null);
  protected readonly isDeleting = signal(false);
  protected readonly isSavingReview = signal(false);
  protected readonly reviewError = signal<string | null>(null);
  protected readonly isSavingSendMessage = signal(false);
  protected readonly sendMessageError = signal<string | null>(null);
  protected readonly reviewFieldErrors = signal<Record<string, string> | null>(null);
  protected readonly months = MONTHS;
  protected readonly sendStatuses = SubDocumentSendStatus;

  protected readonly filterForm = createDocumentFilterForm();

  protected readonly activeFilters = signal<DocumentFilters>({});
  protected readonly hasActiveFilters = computed(() => Object.keys(this.activeFilters()).length > 0);
  protected readonly filteredDocuments = signal<SubDocument[]>([]);
  protected readonly documentsError = signal<string | null>(null);

  private readonly destroyRef = inject(DestroyRef);

  private readonly workflow = inject(DocumentWorkflowService);

  constructor() {
    this.filterForm.valueChanges
      .pipe(
        debounceTime(300),
        distinctUntilChanged(
          (previous, current) =>
            previous.search === current.search &&
            previous.sendStatus === current.sendStatus &&
            previous.confidenceCriterion === current.confidenceCriterion &&
            previous.confidenceThreshold === current.confidenceThreshold &&
            previous.month === current.month &&
            previous.year === current.year
        ),
        takeUntilDestroyed()
      )
      .subscribe((value) => this.activeFilters.set(toDocumentFilters(value)));

    // Una sola sorgente per l'elenco: i filtri e ogni mutazione dello stato
    // (upload, revisione, eliminazione) provocano una rilettura dal backend.
    effect(() => {
      this.store.documents();
      const filters = this.activeFilters();

      this.workflow
        .searchDocuments(filters)
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe({
          next: (documents) => {
            this.filteredDocuments.set(documents);
            this.documentsError.set(null);
          },
          error: (error: unknown) =>
            this.documentsError.set(getApiErrorMessage(error, "Storico documenti non disponibile."))
        });
    });
  }

  protected resetFilters(): void {
    this.filterForm.reset();
  }


  protected selectDocument(documentId: string | null): void {
    this.selectedDocumentId.set(documentId);
  }

  protected upload(request: DocumentUploadRequest): void {
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

  protected deleteDocument(documentId: string): void {
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

  protected markReviewed(documentId: string): void {
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

  protected saveReview(event: { documentId: string; payload: UpdateExtractedDataRequest }): void {
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

  protected saveSendMessage(event: { documentId: string; payload: UpdateSendMessageRequest }): void {
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
