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
import { GeneratedCommunicationPreviewComponent } from "./components/generated-communication-preview";
import { communicationStyles, communicationTones } from "./assistant.model";
import { AssistantService } from "./data/assistant.service";
import { AssistantPageViewModel } from "./assistant-page.view-model";

/**
 * View dell'AI Assistant: nessuna logica di business qui, solo collante
 * col template e procacciamento delle dipendenze via `inject()` per
 * costruire {@link AssistantPageViewModel}. Il template legge solo `vm.*`,
 * mai `store.*` direttamente. Le uniche eccezioni sono gli `effect()`/
 * `takeUntilDestroyed()` nel costruttore, che richiedono un injection
 * context che il ViewModel (Presentation Model) non ha per costruzione:
 * uno legge i segnali sorgente e chiama `vm.reload()`, che possiede la
 * vera chiamata di ricerca; l'altro legge `vm.pendingScrollTarget()` e,
 * quando non è nullo, esegue lo scroll con `scrollToElement` (l'unica
 * operazione DOM richiesta dal ViewModel, che resta un'unità separata in
 * `shared/util/scroll.ts`) e azzera il segnale — il ViewModel *chiede* lo
 * scroll, non lo esegue né chiama mai la View direttamente.
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
      </mvp-section>
    </section>
  `,
  styleUrls: ["../../shared/styles/page.css", "../../shared/styles/field.css"],
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

    // Una sola sorgente per lo storico: i filtri e ogni mutazione dello stato
    // (generazione, scarto, eliminazione, valutazione) rileggono dal backend.
    // Eccezione documentata: l'effect() richiede un injection context che
    // AssistantPageViewModel (classe pura) non ha per costruzione — legge
    // solo i segnali sorgente, la chiamata di ricerca vera vive in vm.reload().
    effect(() => {
      this.store.history();
      this.vm.activeFilters();
      this.vm.reload();
    });

    // Altra eccezione documentata: il ViewModel non chiama mai la View,
    // quindi lo scroll richiesto passa da un segnale osservato, non da un
    // riferimento alla View.
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
}
