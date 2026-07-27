import { ChangeDetectionStrategy, Component, computed, inject, signal } from "@angular/core";
import { finalize } from "rxjs";
import { AssistantService } from "./data/assistant.service";
import type { Communication, UpdateCommunicationRequest } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { getApiErrorMessage } from "../../core/errors/api-error";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { SectionComponent } from "../../layout/section/section";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { formatFallback } from "../../shared/util/formatters";
import { CommunicationGeneratorPanelComponent } from "./components/communication-generator-panel";
import { GeneratedCommunicationPreviewComponent } from "./components/generated-communication-preview";
import type { CommunicationDraftForm, GeneratedDraft } from "./assistant.model";

@Component({
  selector: "mvp-assistant-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommunicationGeneratorPanelComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    GeneratedCommunicationPreviewComponent,
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
      <mvp-generated-communication-preview
        [draft]="previewDraft()"
        [isSaving]="isSavingDraft()"
        [saveError]="saveDraftError()"
        (saveRequested)="saveDraft($event)"
      />

      <mvp-section id="assistant-history" title="Storico contenuti">
        @if (history().length) {
          @for (communication of history(); track communication.id) {
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
        } @else {
          <mvp-empty-state>Le bozze generate compariranno qui.</mvp-empty-state>
        }
      </mvp-section>
    </section>
  `,
  styleUrls: ["./components/communication-status-card.css", "../overview/overview-page.css"]
})
export class AssistantPage {
  protected readonly store = inject(MvpStateStore);
  protected readonly history = this.store.history;
  protected readonly isGenerating = signal(false);
  protected readonly status = signal("In attesa di istruzioni.");
  protected readonly selectedDraftId = signal<number | null>(null);
  protected readonly latestDraft = signal<GeneratedDraft | null>(null);
  protected readonly isSavingDraft = signal(false);
  protected readonly saveDraftError = signal<string | null>(null);
  protected readonly formatFallback = formatFallback;
  protected readonly previewDraft = computed(() => {
    const selectedId = this.selectedDraftId();

    if (selectedId === null) {
      return this.latestDraft();
    }

    const record = this.history().find((communication) => communication.id === selectedId);
    return record ? this.toDraft(record) : this.latestDraft();
  });

  private readonly assistant = inject(AssistantService);

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

  protected saveDraft(event: { communicationId: number; payload: UpdateCommunicationRequest }): void {
    this.saveDraftError.set(null);
    this.isSavingDraft.set(true);

    this.assistant
      .update(event.communicationId, event.payload)
      .pipe(finalize(() => this.isSavingDraft.set(false)))
      .subscribe({
        next: (response) => {
          this.latestDraft.set(this.toDraft(response.communication));
          this.selectedDraftId.set(response.communication.id);
        },
        error: (error: unknown) => {
          this.saveDraftError.set(getApiErrorMessage(error, "Salvataggio non disponibile."));
        }
      });
  }

  private toDraft(communication: Communication): GeneratedDraft {
    return {
      id: communication.id,
      title: communication.title,
      body: communication.body,
      status: communication.status,
      statusValue: communication.statusValue ?? "draft"
    };
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
