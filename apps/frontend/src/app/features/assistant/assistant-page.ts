import { ChangeDetectionStrategy, Component, computed, inject, signal } from "@angular/core";
import { LucideTrash2 } from "@lucide/angular";
import { finalize } from "rxjs";
import { AssistantService } from "./data/assistant.service";
import type { Communication, UpdateCommunicationRequest } from "../../../api/generated/model";
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
import type {
  CommunicationDraftForm,
  CommunicationGenerationPhase,
  CommunicationGenerationProgress,
  GeneratedDraft,
  RateDraftPayload
} from "./assistant.model";

@Component({
  selector: "mvp-assistant-page",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    CommunicationGeneratorPanelComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    GeneratedCommunicationPreviewComponent,
    LucideTrash2,
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
        [isGenerating]="isGenerating()"
        [isDiscarding]="isDiscarding()"
        [isRating]="isRating()"
        [rateError]="rateError()"
        [isSaving]="isSavingDraft()"
        [saveError]="saveDraftError()"
        (uploadCover)="uploadCover($event)"
        (removeCover)="removeCover()"
        (regenerate)="regenerate()"
        (discard)="discard()"
        (rate)="rateDraft($event)"
        (saveRequested)="saveDraft($event)"
      />

      <mvp-section id="assistant-history" title="Storico contenuti">
        @if (history().length) {
          @for (communication of history(); track communication.id) {
            <div class="card" [class.isSelected]="communication.id === selectedDraftId()">
              <button
                type="button"
                class="cardSelect"
                [attr.aria-pressed]="communication.id === selectedDraftId()"
                (click)="selectDraft(communication.id)"
              >
                <mvp-status-badge>{{ communication.status }}</mvp-status-badge>
                <span class="title">{{ communication.title }}</span>
              </button>
              <div class="cardFooter">
                <p>{{ formatFallback(communication.createdAt) }}</p>
                @if (confirmingDeleteId() !== communication.id) {
                  <button
                    mvpButton
                    variant="icon"
                    type="button"
                    aria-label="Elimina dallo storico"
                    [disabled]="isDeletingHistoryItem()"
                    (click)="confirmingDeleteId.set(communication.id)"
                  >
                    <svg lucideTrash2 aria-hidden="true"></svg>
                  </button>
                }
              </div>
              @if (confirmingDeleteId() === communication.id) {
                <div class="cardConfirm">
                  <p class="warning" role="status">Eliminare definitivamente questo elemento dallo storico?</p>
                  <div class="cardActions">
                    <button
                      mvpButton
                      type="button"
                      [disabled]="isDeletingHistoryItem()"
                      (click)="deleteHistoryItem(communication.id)"
                    >
                      Conferma eliminazione
                    </button>
                    <button
                      mvpButton
                      type="button"
                      variant="secondary"
                      [disabled]="isDeletingHistoryItem()"
                      (click)="confirmingDeleteId.set(null)"
                    >
                      Annulla
                    </button>
                  </div>
                </div>
              }
            </div>
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
  protected readonly isDiscarding = signal(false);
  protected readonly confirmingDeleteId = signal<number | null>(null);
  protected readonly isDeletingHistoryItem = signal(false);
  protected readonly isRating = signal(false);
  protected readonly rateError = signal<string | null>(null);
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
    this.phase.set("queued");
    this.status.set("Generazione in corso.");
    this.selectedDraftId.set(null);
    this.latestDraft.set(null);
    this.rateError.set(null);

    this.assistant.generate(payload).subscribe({
      next: (progress) => this.handleProgress(progress),
      error: (error: unknown) => {
        this.phase.set("failed");
        this.status.set(getApiErrorMessage(error, "Generazione non disponibile."));
      }
    });
  }

  protected regenerate(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.phase.set("queued");
    this.status.set("Rigenerazione in corso.");
    // Stesso id, non una nuova bozza: manteniamo la visuale sull'ultima
    // versione (latestDraft) invece che sulla voce di storico selezionata,
    // cosi' il testo e la copertina si aggiornano per gradi come in generate().
    this.selectedDraftId.set(null);
    this.latestDraft.set(draft);

    this.assistant.regenerate(draft.id).subscribe({
      next: (progress) => this.handleProgress(progress),
      error: (error: unknown) => {
        this.phase.set("failed");
        this.status.set(getApiErrorMessage(error, "Rigenerazione non disponibile."));
      }
    });
  }

  protected rateDraft(payload: RateDraftPayload): void {
    const draft = this.previewDraft();
    if (!draft || draft.rating != null) {
      return;
    }

    this.isRating.set(true);
    this.rateError.set(null);

    this.assistant
      .rate(draft.id, payload)
      .pipe(finalize(() => this.isRating.set(false)))
      .subscribe({
        next: (response) => {
          const rated = this.toDraft(response.communication);
          this.latestDraft.set(rated);
          this.selectedDraftId.set(rated.id);
          this.status.set(response.message);
        },
        error: (error: unknown) => {
          this.rateError.set(
            getApiErrorMessage(error, "Valutazione non disponibile. Riprova.")
          );
        }
      });
  }

  protected selectDraft(communicationId: number): void {
    this.selectedDraftId.set(communicationId);
    this.rateError.set(null);
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

  protected discard(): void {
    const draft = this.previewDraft();

    if (!draft) {
      return;
    }

    this.isDiscarding.set(true);
    this.status.set("Eliminazione bozza in corso.");

    this.assistant
      .discard(draft.id)
      .pipe(finalize(() => this.isDiscarding.set(false)))
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
          this.selectedDraftId.set(null);
          this.latestDraft.set(null);
          this.scrollTo("assistant-compose");
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Eliminazione bozza non disponibile."));
        }
      });
  }

  protected deleteHistoryItem(communicationId: number): void {
    this.isDeletingHistoryItem.set(true);

    this.assistant
      .deleteFromHistory(communicationId)
      .pipe(
        finalize(() => {
          this.isDeletingHistoryItem.set(false);
          this.confirmingDeleteId.set(null);
        })
      )
      .subscribe({
        next: (response) => {
          this.status.set(response.message);
          // L'elemento eliminato non deve restare in anteprima.
          if (this.selectedDraftId() === communicationId) {
            this.selectedDraftId.set(null);
          }
          if (this.latestDraft()?.id === communicationId) {
            this.latestDraft.set(null);
          }
        },
        error: (error: unknown) => {
          this.status.set(getApiErrorMessage(error, "Eliminazione dallo storico non disponibile."));
        }
      });
  }

  private handleProgress(progress: CommunicationGenerationProgress): void {
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
      generationStatus: communication.generationStatus,
      rating: communication.rating ?? null,
      ratingComment: communication.ratingComment ?? null,
      ratedAt: communication.ratedAt ?? null,
      statusValue: communication.statusValue ?? "draft"
    };
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
