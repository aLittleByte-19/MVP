import { ChangeDetectionStrategy, Component, computed, inject, signal } from "@angular/core";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { FormControl, FormGroup, ReactiveFormsModule } from "@angular/forms";
import { debounceTime, distinctUntilChanged, finalize } from "rxjs";
import { AssistantService, type CommunicationFilters } from "./data/assistant.service";
import type { Communication } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { getApiErrorMessage } from "../../core/errors/api-error";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { SectionComponent } from "../../layout/section/section";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { ButtonComponent } from "../../shared/components/button/button";
import { formatFallback } from "../../shared/util/formatters";
import { CommunicationGeneratorPanelComponent } from "./components/communication-generator-panel";
import { GeneratedCommunicationPreviewComponent } from "./components/generated-communication-preview";
import { communicationStyles, communicationTones } from "./assistant.model";
import type { CommunicationDraftForm, GeneratedDraft } from "./assistant.model";

@Component({
  selector: "mvp-assistant-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    CommunicationGeneratorPanelComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    GeneratedCommunicationPreviewComponent,
    ReactiveFormsModule,
    SectionComponent,
    StatusBadgeComponent
  ],
  template: `
    <section class="view" aria-label="AI Assistant Generativo">
      @if (store.error(); as error) {
        <mvp-error-state [message]="error" />
      }

      <mvp-communication-generator-panel
        [isGenerating]="isGenerating()"
        [status]="status()"
        (generate)="generate($event)"
      />
      <mvp-generated-communication-preview [draft]="previewDraft()" />

      <mvp-section id="assistant-history" title="Storico contenuti">
        <span actions>{{ filteredCommunications().length }} record</span>

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

        @if (filteredCommunications().length) {
          @for (communication of filteredCommunications(); track communication.id) {
            <button
              type="button"
              class="card"
              [class.isSelected]="communication.id === selectedDraftId()"
              [attr.aria-pressed]="communication.id === selectedDraftId()"
              (click)="selectDraft(communication.id)"
            >
              <mvp-status-badge>{{ communication.status }}</mvp-status-badge>
              <span class="title">{{ communication.title }}</span>
              <p>{{ formatFallback(communication.createdAt) }}</p>
            </button>
          }
        } @else if (hasActiveFilters()) {
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
  protected readonly history = this.store.history;
  protected readonly isGenerating = signal(false);
  protected readonly status = signal("In attesa di istruzioni.");
  protected readonly selectedDraftId = signal<number | null>(null);
  protected readonly latestDraft = signal<GeneratedDraft | null>(null);
  protected readonly formatFallback = formatFallback;
  protected readonly tones = communicationTones;
  protected readonly styles = communicationStyles;

  protected readonly previewDraft = computed(() => {
    const selectedId = this.selectedDraftId();

    if (selectedId === null) {
      return this.latestDraft();
    }

    const record = this.history().find((communication) => communication.id === selectedId);
    return record ? this.toDraft(record) : this.latestDraft();
  });

  protected readonly filterForm = new FormGroup({
    keyword: new FormControl("", { nonNullable: true }),
    tone: new FormControl("", { nonNullable: true }),
    style: new FormControl("", { nonNullable: true }),
    date: new FormControl("", { nonNullable: true })
  });

  protected readonly activeFilters = signal<CommunicationFilters>({});
  protected readonly hasActiveFilters = computed(
    () => Object.keys(this.activeFilters()).length > 0
  );
  protected readonly filteredCommunications = computed(() =>
    this.assistant.getFilteredCommunications(this.activeFilters())
  );

  private readonly assistant = inject(AssistantService);

  constructor() {
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
      .subscribe((value) => this.activeFilters.set(value));
  }

  protected generate(payload: CommunicationDraftForm): void {
    this.isGenerating.set(true);
    this.status.set("Generazione in corso.");

    this.assistant
      .generate(payload)
      .pipe(finalize(() => this.isGenerating.set(false)))
      .subscribe({
        next: (response) => {
          this.selectedDraftId.set(null);
          this.latestDraft.set(this.toDraft(response.communication));
          this.status.set(response.message);
          this.scrollTo("assistant-review");
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Generazione non disponibile."));
        }
      });
  }

  protected selectDraft(communicationId: number): void {
    this.selectedDraftId.set(communicationId);
    this.scrollTo("assistant-review");
  }

  protected resetFilters(): void {
    this.filterForm.reset();
  }

  private toDraft(communication: Communication): GeneratedDraft {
    return {
      title: communication.title,
      body: communication.body,
      status: communication.status
    };
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
