import { ChangeDetectionStrategy, Component, computed, inject, signal } from "@angular/core";
import { finalize } from "rxjs";
import { AssistantService } from "./data/assistant.service";
import type { Communication } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { getApiErrorMessage } from "../../core/errors/api-error";
import { EmptyStateComponent } from "../../shared/components/empty-state/empty-state";
import { ErrorStateComponent } from "../../shared/components/error-state/error-state";
import { SectionComponent } from "../../layout/section/section";
import { StatusBadgeComponent } from "../../shared/components/status-badge/status-badge";
import { formatFallback } from "../../shared/util/formatters";
import { CommunicationGeneratorPanelComponent } from "./components/communication-generator-panel";
import { GeneratedCommunicationPreviewComponent } from "./components/generated-communication-preview";
import type {
  CommunicationDraftForm,
  CommunicationGenerationPhase,
  GeneratedDraft
} from "./assistant.model";

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
        [isUpdatingCover]="isUpdatingCover()"
        (uploadCover)="uploadCover($event)"
        (removeCover)="removeCover()"
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
  protected readonly phase = signal<CommunicationGenerationPhase | "idle">("idle");
  protected readonly isGenerating = computed(
    () => this.phase() === "queued" || this.phase() === "generating-text" || this.phase() === "generating-cover"
  );
  protected readonly isUpdatingCover = signal(false);
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
    this.phase.set("queued");
    this.status.set("Generazione in corso.");
    this.selectedDraftId.set(null);
    this.latestDraft.set(null);

    this.assistant.generate(payload).subscribe({
      next: (progress) => {
        this.phase.set(progress.phase);
        this.status.set(progress.status);

        // Il testo arriva prima della copertina: la bozza si popola per gradi.
        if (progress.text) {
          this.latestDraft.set({
            id: progress.communicationId,
            title: progress.text.title ?? "",
            body: progress.text.body ?? "",
            status: "draft",
            coverStatus: "processing"
          });
          this.scrollTo("assistant-review");
        }

        if (progress.cover) {
          const current = this.latestDraft();
          this.latestDraft.set({
            id: progress.communicationId,
            title: current?.title ?? "",
            body: current?.body ?? "",
            status: current?.status ?? "draft",
            coverImageUrl: progress.cover.coverImageUrl ?? undefined,
            coverStatus: progress.cover.coverStatus,
            coverError: progress.cover.coverError ?? undefined
          });
        }

        if (progress.communication) {
          this.latestDraft.set(this.toDraft(progress.communication));
        }
      },
      error: (error: unknown) => {
        this.phase.set("failed");
        this.status.set(getApiErrorMessage(error, "Generazione non disponibile."));
      }
    });
  }

  protected selectDraft(communicationId: number): void {
    this.selectedDraftId.set(communicationId);
    this.scrollTo("assistant-review");
  }

  protected uploadCover(file: File): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isUpdatingCover.set(true);
    this.status.set("Caricamento immagine in corso.");

    this.assistant
      .updateCoverImage(draft.id, file)
      .pipe(finalize(() => this.isUpdatingCover.set(false)))
      .subscribe({
        next: (response) => {
          this.latestDraft.set(this.toDraft(response.communication));
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Aggiornamento immagine non disponibile."));
        }
      });
  }

  protected removeCover(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isUpdatingCover.set(true);
    this.status.set("Rimozione immagine in corso.");

    this.assistant
      .removeCoverImage(draft.id)
      .pipe(finalize(() => this.isUpdatingCover.set(false)))
      .subscribe({
        next: (response) => {
          this.latestDraft.set(this.toDraft(response.communication));
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Rimozione immagine non disponibile."));
        }
      });
  }

  private toDraft(communication: Communication): GeneratedDraft {
    return {
      id: communication.id,
      title: communication.title ?? "",
      body: communication.body ?? "",
      status: communication.status,
      coverImageUrl: communication.coverImageUrl ?? undefined,
      coverStatus: communication.coverStatus,
      coverError: communication.coverError ?? undefined,
      previewUrl: communication.previewUrl,
      exportUrl: communication.exportUrl,
      generationStatus: communication.generationStatus
    };
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
