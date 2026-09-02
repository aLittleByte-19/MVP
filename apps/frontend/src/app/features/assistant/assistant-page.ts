import { ChangeDetectionStrategy, Component, DestroyRef, effect, inject } from "@angular/core";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { FormControl, FormGroup, ReactiveFormsModule } from "@angular/forms";
import { debounceTime, distinctUntilChanged } from "rxjs";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { ButtonComponent } from "../../shared/components/button/button";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { SectionComponent } from "../../layout/section/section";
import { MetricCompositionComponent } from "../../shared/components/metric-composition/metric-composition";
import { MetricsPanelComponent } from "../../shared/components/metrics-panel/metrics-panel";
import { scrollToElement } from "../../shared/util/scroll";
import { CommunicationGeneratorPanelComponent } from "./components/communication-generator-panel";
import { CommunicationHistoryListComponent } from "./components/communication-history-list";
import { PromptConfigurationListComponent } from "./components/prompt-configuration-list";
import { RecentFeedbackListComponent } from "./components/recent-feedback-list";
import { GeneratedCommunicationPreviewComponent } from "./components/generated-communication-preview";
import { communicationStyles, communicationTones } from "./assistant.model";
import { AssistantService } from "./data/assistant.service";
import { AssistantPageViewModel } from "./assistant-page.view-model";

/**
 * View dell'AI Assistant: solo collante col template, costruisce
 * {@link AssistantPageViewModel}. Il template legge solo `vm.*`. Gli
 * `effect()`/`takeUntilDestroyed()` nel costruttore sono l'eccezione MVVM
 * documentata: il ViewModel non ha injection context, quindi leggono i
 * segnali sorgente e delegano la logica vera al ViewModel.
 */
@Component({
  selector: "mvp-assistant-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    CommunicationGeneratorPanelComponent,
    CommunicationHistoryListComponent,
    ErrorStateComponent,
    GeneratedCommunicationPreviewComponent,
    MetricCompositionComponent,
    MetricsPanelComponent,
    PromptConfigurationListComponent,
    ReactiveFormsModule,
    RecentFeedbackListComponent,
    SectionComponent
  ],
  template: `
    <section class="view" aria-label="AI Assistant Generativo">
      @if (vm.error(); as error) {
        <mvp-error-state [message]="error" [canRetry]="true" (retry)="vm.reloadState()" />
      }

      <mvp-communication-generator-panel
        [isGenerating]="vm.isGenerating()"
        [status]="vm.status()"
        [phase]="vm.phase()"
        [promptConfigurations]="vm.promptConfigurations()"
        [isSavingConfiguration]="vm.isSavingConfiguration()"
        [saveConfigurationError]="vm.saveConfigurationError()"
        [prefill]="vm.prefillPayload()"
        (generate)="vm.generate($event)"
        (saveConfiguration)="vm.saveConfiguration($event)"
      />
      <mvp-generated-communication-preview
        [draft]="vm.previewDraft()"
        [isUpdatingCover]="vm.isUpdatingCover()"
        [isGenerating]="vm.isGenerating()"
        [isDiscarding]="vm.isDiscarding()"
        [isSavingToHistory]="vm.isSavingToHistory()"
        [isRating]="vm.isRating()"
        [rateError]="vm.rateError()"
        [isSaving]="vm.isSavingDraft()"
        [saveError]="vm.saveDraftError()"
        (uploadCover)="vm.uploadCover($event)"
        (removeCover)="vm.removeCover()"
        (regenerate)="vm.regenerate()"
        (discard)="vm.discard()"
        (saveToHistory)="vm.saveToHistory()"
        (rate)="vm.rateDraft($event)"
        (saveRequested)="vm.saveDraft($event)"
      />

      <mvp-section id="assistant-history" title="Storico contenuti">
        @if (vm.historyError(); as error) {
          <mvp-error-state [message]="error" />
        }

        <span actions>{{ vm.filteredCommunications().length }} record</span>

        <div class="filters" role="search" [formGroup]="filterForm" aria-label="Filtra storico comunicazioni">
          <label class="field" for="filter-keyword">
            <span>Parola chiave</span>
            <input id="filter-keyword" type="text" formControlName="keyword" placeholder="Cerca nel prompt" />
          </label>
          <label class="field" for="filter-tone">
            <span>Tono</span>
            <select id="filter-tone" formControlName="tone">
              <option value="">Tutti i toni</option>
              @for (tone of tones; track tone) {
                <option [value]="tone">{{ tone }}</option>
              }
            </select>
          </label>
          <label class="field" for="filter-style">
            <span>Stile</span>
            <select id="filter-style" formControlName="style">
              <option value="">Tutti gli stili</option>
              @for (style of styles; track style) {
                <option [value]="style">{{ style }}</option>
              }
            </select>
          </label>
          <label class="field" for="filter-date">
            <span>Data</span>
            <input id="filter-date" type="date" formControlName="date" />
          </label>
          <button mvpButton variant="secondary" type="button" (click)="resetFilters()">Azzera filtri</button>
        </div>

        <mvp-prompt-configuration-list
          [configurations]="vm.filteredPromptConfigurations()"
          [confirmingDeleteId]="vm.confirmingConfigDeleteId()"
          [isDeleting]="vm.isDeletingConfig()"
          (useRequested)="vm.useConfiguration($event)"
          (confirmDeleteRequested)="vm.confirmingConfigDeleteId.set($event)"
          (deleteRequested)="vm.deleteConfiguration($event)"
        />

        <mvp-communication-history-list
          [communications]="vm.filteredCommunications()"
          [selectedId]="vm.selectedDraftId()"
          [confirmingDeleteId]="vm.confirmingDeleteId()"
          [isDeleting]="vm.isDeletingHistoryItem()"
          [togglingFavoriteId]="vm.togglingFavoriteId()"
          [hasActiveFilters]="vm.hasActiveFilters()"
          (selected)="vm.selectDraft($event)"
          (favoriteToggled)="vm.toggleFavorite($event)"
          (confirmDeleteRequested)="vm.confirmingDeleteId.set($event)"
          (deleteRequested)="vm.deleteHistoryItem($event)"
        />
      </mvp-section>

      <mvp-section id="assistant-metrics" title="Qualità della generazione">
        <a actions class="downloadLink" [href]="vm.metricsReportExportUrl">Esporta report</a>
        <div
          class="filters"
          role="search"
          [formGroup]="metricsFilterForm"
          aria-label="Filtra le metriche dell'AI Assistant"
        >
          <label class="field" for="metrics-filter-tone">
            <span>Tono</span>
            <select id="metrics-filter-tone" formControlName="tone">
              <option value="">Tutti i toni</option>
              @for (tone of tones; track tone) {
                <option [value]="tone">{{ tone }}</option>
              }
            </select>
          </label>
          <label class="field" for="metrics-filter-style">
            <span>Stile</span>
            <select id="metrics-filter-style" formControlName="style">
              <option value="">Tutti gli stili</option>
              @for (style of styles; track style) {
                <option [value]="style">{{ style }}</option>
              }
            </select>
          </label>
          <label class="field" for="metrics-filter-date-from">
            <span>Da</span>
            <input id="metrics-filter-date-from" type="date" formControlName="dateFrom" />
          </label>
          <label class="field" for="metrics-filter-date-to">
            <span>A</span>
            <input id="metrics-filter-date-to" type="date" formControlName="dateTo" />
          </label>
          <button mvpButton variant="secondary" type="button" (click)="resetMetricsFilters()">Azzera filtri</button>
        </div>
        <mvp-metrics-panel
          [isLoading]="vm.loading()"
          [hasError]="!!vm.error()"
          [metrics]="vm.metrics()"
          [presentation]="vm.metricsPresentation()"
          ariaLabel="Metriche dell'AI Assistant"
        />
        <h3>Esito delle bozze</h3>
        <mvp-metric-composition
          [parts]="vm.draftComposition()"
          subject="comunicazioni"
          emptyLabel="Nessuna comunicazione ancora generata."
        />
        <h3>Feedback recenti</h3>
        <mvp-recent-feedback-list [feedback]="vm.recentFeedback()" />
      </mvp-section>
    </section>
  `,
  styleUrls: ["../../shared/styles/page.css", "../../shared/styles/field.css", "../../shared/styles/link-button.css"],
  styles: [
    `
    .filters {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr)) auto;
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
export class AssistantPage {
  protected readonly store = inject(MvpStateStore);
  protected readonly tones = communicationTones;
  protected readonly styles = communicationStyles;

  protected readonly filterForm = new FormGroup({
    keyword: new FormControl("", { nonNullable: true }),
    tone: new FormControl("", { nonNullable: true }),
    style: new FormControl("", { nonNullable: true }),
    date: new FormControl("", { nonNullable: true })
  });

  /** Filtro separato per la sezione Metriche (RF38-OB..RF41-OB): non condivide stato con `filterForm` qui sopra. */
  protected readonly metricsFilterForm = new FormGroup({
    tone: new FormControl("", { nonNullable: true }),
    style: new FormControl("", { nonNullable: true }),
    dateFrom: new FormControl("", { nonNullable: true }),
    dateTo: new FormControl("", { nonNullable: true })
  });

  protected readonly vm: AssistantPageViewModel;

  constructor() {
    this.vm = new AssistantPageViewModel(inject(AssistantService), this.store);

    this.filterForm.valueChanges
      .pipe(
        debounceTime(300),
        distinctUntilChanged(
          (previous, current) =>
            previous.keyword === current.keyword &&
            previous.tone === current.tone &&
            previous.style === current.style &&
            previous.date === current.date
        ),
        takeUntilDestroyed()
      )
      .subscribe((value) => this.vm.setActiveFilters(value));

    this.metricsFilterForm.valueChanges
      .pipe(
        debounceTime(300),
        distinctUntilChanged(
          (previous, current) =>
            previous.tone === current.tone &&
            previous.style === current.style &&
            previous.dateFrom === current.dateFrom &&
            previous.dateTo === current.dateTo
        ),
        takeUntilDestroyed()
      )
      .subscribe((value) => this.vm.setMetricsFilters(value));

    effect(() => {
      this.store.history();
      this.vm.activeFilters();
      this.vm.reload();
    });

    effect(() => {
      this.vm.metricsFilters();
      this.vm.refreshFilteredMetrics();
    });

    // Il ViewModel non chiama mai la View: lo scroll richiesto passa da un segnale osservato.
    effect(() => {
      const target = this.vm.pendingScrollTarget();

      if (target !== null) {
        scrollToElement(target);
        this.vm.pendingScrollTarget.set(null);
      }
    });

    inject(DestroyRef).onDestroy(() => this.vm.destroy());
  }

  protected resetFilters(): void {
    this.filterForm.reset();
  }

  protected resetMetricsFilters(): void {
    this.metricsFilterForm.reset();
  }
}
