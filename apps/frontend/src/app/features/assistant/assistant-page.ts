import { ChangeDetectionStrategy, Component, computed, inject, signal } from "@angular/core";
import { finalize } from "rxjs";
import { AssistantService } from "./data/assistant.service";
import type { Communication } from "../../../api/generated/model";
import { PocStateStore } from "../../core/state/poc-state.store";
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
  selector: "poc-assistant-page",
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
        <poc-error-state [message]="error" />
      }

      <poc-communication-generator-panel
        [isGenerating]="isGenerating()"
        [status]="status()"
        (generate)="generate($event)"
      />
      <poc-generated-communication-preview [draft]="previewDraft()" />

      <poc-section id="assistant-history" title="Storico contenuti">
        @if (history().length) {
          @for (communication of history(); track communication.id) {
            <button
              type="button"
              class="card"
              [class.isSelected]="communication.id === selectedDraftId()"
              [attr.aria-pressed]="communication.id === selectedDraftId()"
              (click)="selectDraft(communication.id)"
            >
              <poc-status-badge>{{ communication.status }}</poc-status-badge>
              <span class="title">{{ communication.title }}</span>
              <p>{{ formatFallback(communication.createdAt) }}</p>
            </button>
          }
        } @else {
          <poc-empty-state>Le bozze generate compariranno qui.</poc-empty-state>
        }
      </poc-section>
    </section>
  `,
  styleUrls: ["./components/communication-status-card.css", "../overview/overview-page.css"]
})
export class AssistantPage {
  protected readonly store = inject(PocStateStore);
  protected readonly history = this.store.history;
  protected readonly isGenerating = signal(false);
  protected readonly status = signal("In attesa di istruzioni.");
  protected readonly selectedDraftId = signal<number | null>(null);
  protected readonly latestDraft = signal<GeneratedDraft | null>(null);
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
