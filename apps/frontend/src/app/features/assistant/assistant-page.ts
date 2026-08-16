import { ChangeDetectionStrategy, Component, DestroyRef, effect, inject } from "@angular/core";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { FormControl, FormGroup, ReactiveFormsModule } from "@angular/forms";
import { LucideStar, LucideTrash2 } from "@lucide/angular";
import { debounceTime, distinctUntilChanged } from "rxjs";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { ButtonComponent } from "../../shared/components/button/button";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { SectionComponent } from "../../layout/section/section";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { formatDateForDisplay, formatFallback } from "../../shared/util/formatters";
import { scrollToElement } from "../../shared/util/scroll";
import { CommunicationGeneratorPanelComponent } from "./components/communication-generator-panel";
import { GeneratedCommunicationPreviewComponent } from "./components/generated-communication-preview";
import { communicationStyles, communicationTones } from "./assistant.model";
import { AssistantService } from "./data/assistant.service";
import { AssistantPageViewModel } from "./assistant-page.view-model";

/**
 * View dell'AI Assistant: nessuna logica di business qui, solo collante
 * col template e procacciamento delle dipendenze via `inject()` per
 * costruire {@link AssistantPageViewModel} — incluso `scrollToElement`,
 * l'unica operazione DOM richiesta dal ViewModel, che qui resta un'unità
 * separata (`shared/util/scroll.ts`) invece di un metodo del ViewModel.
 * Il template legge solo `vm.*`, mai `store.*` direttamente. Le uniche
 * eccezioni sono `effect()`/`takeUntilDestroyed()` nel costruttore, che
 * richiedono un injection context che il ViewModel (Presentation Model)
 * non ha per costruzione — l'effect si limita a leggere i segnali sorgente
 * e chiamare `vm.reload()`, che possiede la vera chiamata di ricerca.
 */
@Component({
  selector: "mvp-assistant-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    CommunicationGeneratorPanelComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    GeneratedCommunicationPreviewComponent,
    LucideStar,
    LucideTrash2,
    ReactiveFormsModule,
    SectionComponent,
    StatusBadgeComponent
  ],
  template: `
    <section class="view" aria-label="AI Assistant Generativo">
      @if (vm.error(); as error) {
        <mvp-error-state [message]="error" [canRetry]="true" (retry)="store.reload()" />
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

        <form class="filters" [formGroup]="filterForm" aria-label="Filtra storico comunicazioni">
          <label class="field" for="filter-keyword">
            <span>Parola chiave</span>
            <input id="filter-keyword" type="text" formControlName="keyword" placeholder="Cerca nel prompt..." />
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
        </form>

        @if (vm.filteredPromptConfigurations().length) {
          <div class="saved-configs">
            <span class="saved-configs-label">Configurazioni salvate</span>
            @for (configuration of vm.filteredPromptConfigurations(); track configuration.id) {
              <div class="saved-config">
                <div class="saved-config-header">
                  <span>
                    {{ configuration.name }}
                    <span class="saved-config-date">{{ formatDateForDisplay(configuration.createdAt) }}</span>
                  </span>
                  <div class="saved-config-actions">
                    <button mvpButton variant="secondary" type="button" (click)="vm.useConfiguration(configuration)">
                      Usa
                    </button>
                    @if (vm.confirmingConfigDeleteId() !== configuration.id) {
                      <button
                        mvpButton
                        variant="icon"
                        type="button"
                        aria-label="Elimina configurazione salvata"
                        [disabled]="vm.isDeletingConfig()"
                        (click)="vm.confirmingConfigDeleteId.set(configuration.id)"
                      >
                        <svg lucideTrash2 aria-hidden="true"></svg>
                      </button>
                    }
                  </div>
                </div>
                @if (vm.confirmingConfigDeleteId() === configuration.id) {
                  <div class="cardConfirm">
                    <p class="warning" role="status">Eliminare definitivamente questa configurazione salvata?</p>
                    <div class="cardActions">
                      <button
                        mvpButton
                        type="button"
                        [disabled]="vm.isDeletingConfig()"
                        (click)="vm.deleteConfiguration(configuration.id)"
                      >
                        Conferma eliminazione
                      </button>
                      <button
                        mvpButton
                        type="button"
                        variant="secondary"
                        [disabled]="vm.isDeletingConfig()"
                        (click)="vm.confirmingConfigDeleteId.set(null)"
                      >
                        Annulla
                      </button>
                    </div>
                  </div>
                }
              </div>
            }
          </div>
        }

        @if (vm.filteredCommunications().length) {
          @for (communication of vm.filteredCommunications(); track communication.id) {
            <div class="card" [class.isSelected]="communication.id === vm.selectedDraftId()">
              <button
                type="button"
                class="cardSelect"
                [attr.aria-pressed]="communication.id === vm.selectedDraftId()"
                (click)="vm.selectDraft(communication.id)"
              >
                <mvp-status-badge>{{ communication.status }}</mvp-status-badge>
                <span class="title">{{ communication.title }}</span>
              </button>
              <div class="cardFooter">
                <p>{{ formatFallback(communication.createdAt) }}</p>
                <button
                  mvpButton
                  variant="icon"
                  type="button"
                  class="favoriteToggle"
                  [class.isFavorite]="communication.isFavorite"
                  [attr.aria-pressed]="communication.isFavorite"
                  [attr.aria-label]="
                    communication.isFavorite ? 'Rimuovi dai preferiti' : 'Aggiungi ai preferiti'
                  "
                  [disabled]="vm.togglingFavoriteId() === communication.id"
                  (click)="vm.toggleFavorite(communication)"
                >
                  <svg lucideStar aria-hidden="true"></svg>
                </button>
                @if (vm.confirmingDeleteId() !== communication.id) {
                  <button
                    mvpButton
                    variant="icon"
                    type="button"
                    aria-label="Elimina dallo storico"
                    [disabled]="vm.isDeletingHistoryItem()"
                    (click)="vm.confirmingDeleteId.set(communication.id)"
                  >
                    <svg lucideTrash2 aria-hidden="true"></svg>
                  </button>
                }
              </div>
              @if (vm.confirmingDeleteId() === communication.id) {
                <div class="cardConfirm">
                  <p class="warning" role="status">Eliminare definitivamente questo elemento dallo storico?</p>
                  <div class="cardActions">
                    <button
                      mvpButton
                      type="button"
                      [disabled]="vm.isDeletingHistoryItem()"
                      (click)="vm.deleteHistoryItem(communication.id)"
                    >
                      Conferma eliminazione
                    </button>
                    <button
                      mvpButton
                      type="button"
                      variant="secondary"
                      [disabled]="vm.isDeletingHistoryItem()"
                      (click)="vm.confirmingDeleteId.set(null)"
                    >
                      Annulla
                    </button>
                  </div>
                </div>
              }
            </div>
          }
        } @else if (vm.hasActiveFilters()) {
          <mvp-empty-state>Nessuna comunicazione corrisponde ai filtri selezionati.</mvp-empty-state>
        } @else {
          <mvp-empty-state>Le bozze generate compariranno qui.</mvp-empty-state>
        }
      </mvp-section>
    </section>
  `,
  styleUrls: ["./components/communication-status-card.css", "../overview/overview-page.css"],
  styles: [
    `
    .saved-configs {
      display: grid;
      gap: var(--mvp-space-2);
      margin-bottom: var(--mvp-space-4);
    }

    .saved-configs-label {
      color: var(--mvp-muted);
      font-size: var(--mvp-font-sm);
      font-weight: 800;
    }

    .saved-config {
      display: grid;
      gap: var(--mvp-space-2);
      padding: var(--mvp-space-2) var(--mvp-space-3);
      border: 1px solid var(--mvp-border);
      border-radius: var(--mvp-radius);
      background: var(--mvp-surface-muted);
    }

    .saved-config-header {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: var(--mvp-space-3);
    }

    .saved-config-actions {
      display: flex;
      align-items: center;
      gap: var(--mvp-space-2);
    }

    .saved-config-date {
      margin-left: var(--mvp-space-2);
      color: var(--mvp-muted);
      font-size: var(--mvp-font-sm);
      font-weight: 400;
    }

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
  protected readonly formatFallback = formatFallback;
  protected readonly formatDateForDisplay = formatDateForDisplay;

  protected readonly filterForm = new FormGroup({
    keyword: new FormControl("", { nonNullable: true }),
    tone: new FormControl("", { nonNullable: true }),
    style: new FormControl("", { nonNullable: true }),
    date: new FormControl("", { nonNullable: true })
  });

  protected readonly vm: AssistantPageViewModel;

  constructor() {
    this.vm = new AssistantPageViewModel(inject(AssistantService), this.store, scrollToElement);

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

    inject(DestroyRef).onDestroy(() => this.vm.destroy());
  }

  protected resetFilters(): void {
    this.filterForm.reset();
  }
}
