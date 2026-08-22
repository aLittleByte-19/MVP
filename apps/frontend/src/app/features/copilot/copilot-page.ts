import { ChangeDetectionStrategy, Component, DestroyRef, effect, inject } from "@angular/core";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { ReactiveFormsModule } from "@angular/forms";
import { debounceTime, distinctUntilChanged } from "rxjs";
import { SubDocumentSendStatus } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { ButtonComponent } from "../../shared/components/button/button";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { MetricsPanelComponent } from "../../shared/components/metrics-panel/metrics-panel";
import { SectionComponent } from "../../layout/section/section";
import { scrollToElement } from "../../shared/util/scroll";
import { DocumentWorkflowService } from "./data/document-workflow.service";
import { createDocumentFilterForm, toDocumentFilters } from "./data/document-filters";
import { DocumentListComponent } from "./components/document-list";
import { DocumentUploadPanelComponent } from "./components/document-upload-panel";
import { SubDocumentListComponent } from "./components/sub-document-list";
import { CopilotPageViewModel } from "./copilot-page.view-model";

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

/**
 * View del Co-Pilot documentale: nessuna logica di business qui, solo
 * collante col template e procacciamento delle dipendenze via `inject()`
 * per costruire {@link CopilotPageViewModel} (Presentation Model, Fowler).
 * Il template legge solo `vm.*`, mai `store.*` direttamente. Le uniche
 * eccezioni sono gli `effect()`/`takeUntilDestroyed()` nel costruttore, che
 * richiedono un injection context che il ViewModel (classe pura) non ha
 * per costruzione — ciascuno si limita a leggere i segnali sorgente e
 * chiamare `vm.reload()`/`vm.loadPreviewStatus()`, che possiedono la vera
 * logica (anche `mvp-sub-document-list`, sotto, resta un componente
 * "dumb": riceve `previewStatus` come input, non lo produce).
 */
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
      @if (vm.error(); as error) {
        <mvp-error-state [message]="error" [canRetry]="true" (retry)="vm.reloadState()" />
      }

      <mvp-document-upload-panel
        [isUploading]="vm.isUploading()"
        [status]="vm.uploadStatus()"
        [phase]="vm.uploadPhase()"
        (upload)="vm.upload($event)"
      />

      <mvp-section id="copilot-documents" title="Storico documenti analizzati">
        <span actions>{{ vm.filteredDocuments().length }} record</span>

        @if (vm.documentsError(); as error) {
          <mvp-error-state [message]="error" />
        }

        <div class="filters" role="search" [formGroup]="filterForm" aria-label="Filtra storico documenti">
          <label class="field" for="filter-search">
            <span>Nome, cognome o azienda</span>
            <input id="filter-search" type="text" formControlName="search" placeholder="Cerca nello storico" />
          </label>
          <label class="field" for="filter-send-status">
            <span>Stato scaricamento</span>
            <select id="filter-send-status" formControlName="sendStatus">
              <option value="">Tutti</option>
              <option [value]="sendStatuses.sent">Scaricato</option>
              <option [value]="sendStatuses.pending">Non scaricato</option>
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
        </div>

        <mvp-document-list
          [documents]="vm.filteredDocuments()"
          [selectedDocumentId]="vm.selectedDocumentIdForList()"
          [emptyMessage]="
            vm.hasActiveFilters()
              ? 'Nessun documento corrisponde ai filtri selezionati.'
              : 'I documenti caricati compariranno qui.'
          "
          [page]="vm.currentPage()"
          [totalPages]="vm.totalPages()"
          (selectDocument)="selectDocument($event)"
          (goToPage)="vm.goToPage($event)"
        />
      </mvp-section>

      <mvp-sub-document-list
        [documentItem]="vm.selectedDocument()"
        [previewStatus]="vm.previewStatus()"
        [isDeleting]="vm.isDeleting()"
        [isSavingReview]="vm.isSavingReview()"
        [reviewError]="vm.reviewError()"
        [isSavingSendMessage]="vm.isSavingSendMessage()"
        [sendMessageError]="vm.sendMessageError()"
        [fieldErrors]="vm.reviewFieldErrors()"
        (deleteDocument)="vm.deleteDocument($event)"
        (markReviewed)="vm.markReviewed($event)"
        (saveReviewRequested)="vm.saveReview($event)"
        (saveSendMessageRequested)="vm.saveSendMessage($event)"
      />

      <mvp-section id="copilot-metrics" title="Qualità e performance OCR">
        <mvp-metrics-panel
          [isLoading]="vm.loading()"
          [hasError]="!!vm.error()"
          [metrics]="vm.metrics()"
          [presentation]="vm.metricsPresentation()"
          ariaLabel="Metriche del Co-Pilot documentale"
        />
      </mvp-section>
    </section>
  `,
  styleUrls: ["../../shared/styles/page.css", "../../shared/styles/field.css"],
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
  protected readonly months = MONTHS;
  protected readonly sendStatuses = SubDocumentSendStatus;

  protected readonly filterForm = createDocumentFilterForm();

  protected readonly vm: CopilotPageViewModel;

  constructor() {
    this.vm = new CopilotPageViewModel(inject(DocumentWorkflowService), this.store);

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
      .subscribe((value) => this.vm.setActiveFilters(toDocumentFilters(value)));

    // Una sola sorgente per l'elenco: i filtri e ogni mutazione dello stato
    // (upload, revisione, eliminazione) provocano una rilettura dal backend.
    // Eccezione documentata: l'effect() richiede un injection context che
    // CopilotPageViewModel (classe pura) non ha per costruzione — legge solo
    // i segnali sorgente, la chiamata di ricerca vera vive in vm.reload().
    //
    // Non legge `vm.currentPage()` di proposito: `vm.goToPage()` chiama
    // `reload()` da se', e se questo effect osservasse anche la pagina la
    // rilettura partirebbe due volte a ogni cambio pagina. Chi tocca
    // paginazione o filtri deve tenere a mente questo accoppiamento implicito.
    effect(() => {
      this.store.documents();
      this.vm.activeFilters();
      this.vm.reload();
    });

    // Stessa eccezione documentata sopra: la fetch vera vive in
    // vm.loadPreviewStatus(), qui si legge solo il documento selezionato.
    effect(() => {
      const document = this.vm.selectedDocument();
      this.vm.loadPreviewStatus(document?.previewUrl ?? null);
    });

    inject(DestroyRef).onDestroy(() => this.vm.destroy());
  }

  /**
   * Apre il dettaglio e ci porta la pagina.
   *
   * Lo scorrimento sta qui e non nel ViewModel: la View e' l'unica responsabile
   * della resa visiva (ADR 0011), e il dettaglio sta sotto lo storico, che con
   * dieci righe e i filtri sopra puo' restare tutto fuori dallo schermo.
   *
   * L'ancora e' sulla `mvp-section` dentro il pannello, non sull'host di
   * `mvp-sub-document-list`: quell'host ha `display: contents`, quindi non
   * genera alcun box e `scrollIntoView` non ha nulla verso cui scorrere. La
   * sezione invece e' una griglia e porta gia' il proprio `scroll-margin-top`.
   */
  protected selectDocument(documentId: string | null): void {
    this.vm.selectDocument(documentId);

    if (documentId) {
      scrollToElement("copilot-document-detail");
    }
  }

  protected resetFilters(): void {
    this.filterForm.reset();
  }
}
