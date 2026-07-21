import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal
} from "@angular/core";
import { FormControl, ReactiveFormsModule, Validators } from "@angular/forms";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../../layout/section/section";
import { StarRating } from "../../../components/star-rating/star-rating";
import { ButtonComponent } from "../../../shared/components/button/button";
import {
  RATING_COMMENT_MAX_LENGTH,
  type GeneratedDraft,
  type RateDraftPayload
} from "../assistant.model";

@Component({
  selector: "mvp-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EmptyStateComponent,
    SectionComponent,
    StatusBadgeComponent,
    StarRating,
    ButtonComponent,
    ReactiveFormsModule
  ],
  template: `
    <mvp-section id="assistant-review" title="Controlla il contenuto">
      <span actions class="meta">{{ bodyLength() }} caratteri</span>
      @if (draft(); as currentDraft) {
        <article class="draft">
          <div class="cover">
            <span>Bozza generata</span>
          </div>
          <label class="field">
            <span>Titolo</span>
            <input readOnly [value]="currentDraft.title" />
          </label>
          <label class="field">
            <span>Testo</span>
            <textarea readOnly rows="7" [value]="currentDraft.body"></textarea>
          </label>

          <div class="rating-section">
            <span class="rating-label">Valuta questa bozza</span>
            <mvp-star-rating
              [rating]="displayRating()"
              [disabled]="hasRated() || isRating()"
              (rated)="onStarsSelected($event)"
            ></mvp-star-rating>

            @if (!hasRated()) {
              <label class="field comment-field">
                <span>Commento qualitativo (opzionale)</span>
                <textarea
                  rows="3"
                  [formControl]="commentControl"
                  placeholder="Aggiungi un commento alla valutazione"
                ></textarea>
                <span class="comment-meta" [class.isInvalid]="commentTooLong()">
                  {{ commentLength() }} / {{ commentMaxLength }}
                </span>
              </label>

              @if (commentTooLong()) {
                <p class="rating-error" role="alert">
                  Il commento supera la lunghezza massima consentita ({{ commentMaxLength }} caratteri).
                </p>
              }

              @if (localError(); as error) {
                <p class="rating-error" role="alert">{{ error }}</p>
              }

              @if (rateError(); as error) {
                <p class="rating-error" role="alert">{{ error }}</p>
              }

              <button
                mvpButton
                type="button"
                [disabled]="isRating() || !selectedRating() || commentTooLong()"
                (click)="submitRating()"
              >
                {{ isRating() ? "Invio in corso…" : "Invia valutazione" }}
              </button>
            } @else {
              @if (currentDraft.ratingComment) {
                <p class="saved-comment">
                  <span>Commento</span>
                  {{ currentDraft.ratingComment }}
                </p>
              }
              <p class="rating-success">Valutazione registrata con successo.</p>
            }
          </div>

          <div class="footer">
            <mvp-status-badge>{{ currentDraft.status }}</mvp-status-badge>
            <span>Creato da AI Assistant · anteprima in sola lettura</span>
          </div>
        </article>
      } @else {
        <mvp-empty-state>La bozza generata apparira qui.</mvp-empty-state>
      }
    </mvp-section>
  `,
  styleUrl: "./generated-communication-preview.css"
})
export class GeneratedCommunicationPreviewComponent {
  readonly draft = input<GeneratedDraft | null>(null);
  readonly isRating = input(false);
  readonly rateError = input<string | null>(null);
  readonly rate = output<RateDraftPayload>();

  protected readonly commentMaxLength = RATING_COMMENT_MAX_LENGTH;
  protected readonly selectedRating = signal(0);
  protected readonly localError = signal<string | null>(null);
  protected readonly commentControl = new FormControl("", {
    nonNullable: true,
    validators: [Validators.maxLength(RATING_COMMENT_MAX_LENGTH)]
  });

  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);
  protected readonly hasRated = computed(() => this.draft()?.rating != null);
  protected readonly displayRating = computed(
    () => this.draft()?.rating ?? this.selectedRating()
  );
  protected readonly commentLength = signal(0);
  protected readonly commentTooLong = computed(
    () => this.commentLength() > RATING_COMMENT_MAX_LENGTH
  );

  private readonly syncedDraftId = signal<number | null>(null);

  constructor() {
    this.commentControl.valueChanges.subscribe((value) => {
      this.commentLength.set(value.length);
      if (value.length <= RATING_COMMENT_MAX_LENGTH) {
        this.localError.set(null);
      }
    });

    effect(() => {
      const current = this.draft();
      const draftId = current?.id ?? null;
      const alreadySynced = this.syncedDraftId() === draftId && draftId !== null;
      const ratingChanged =
        alreadySynced && current?.rating != null && this.selectedRating() !== current.rating;

      if (alreadySynced && !ratingChanged) {
        return;
      }

      this.syncedDraftId.set(draftId);
      this.selectedRating.set(current?.rating ?? 0);
      this.localError.set(null);
      this.commentControl.setValue(current?.ratingComment ?? "", { emitEvent: true });
      this.commentControl.enable({ emitEvent: false });
      if (current?.rating != null) {
        this.commentControl.disable({ emitEvent: false });
      }
    });
  }

  protected onStarsSelected(stars: number): void {
    if (this.hasRated() || this.isRating()) {
      return;
    }

    this.selectedRating.set(stars);
    this.localError.set(null);
  }

  protected submitRating(): void {
    if (this.hasRated() || this.isRating()) {
      return;
    }

    const rating = this.selectedRating();
    if (rating < 1 || rating > 5) {
      this.localError.set("Seleziona un punteggio da 1 a 5 stelle.");
      return;
    }

    const comment = this.commentControl.value.trim();
    if (comment.length > RATING_COMMENT_MAX_LENGTH) {
      this.localError.set(
        `Il commento supera la lunghezza massima consentita (${RATING_COMMENT_MAX_LENGTH} caratteri).`
      );
      return;
    }

    this.localError.set(null);
    this.rate.emit(comment ? { rating, comment } : { rating });
  }
}
