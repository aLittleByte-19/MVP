import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  output,
  signal
} from "@angular/core";
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from "@angular/forms";
import { LucidePencil, LucideSave, LucideX } from "@lucide/angular";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { ButtonComponent } from "../../../shared/components/button/button";
import { SectionComponent } from "../../../layout/section/section";
import { StarRating } from "../../../shared/components/star-rating/star-rating";
import type { UpdateCommunicationRequest } from "../../../../api/generated/model";
import {
  RATING_COMMENT_MAX_LENGTH,
  type GeneratedDraft,
  type RateDraftPayload
} from "../assistant.model";

@Component({
  selector: "mvp-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    EmptyStateComponent,
    LucidePencil,
    LucideSave,
    LucideX,
    ReactiveFormsModule,
    SectionComponent,
    StarRating,
    StatusBadgeComponent
  ],
  template: `
    <mvp-section id="assistant-review" title="Controlla il contenuto">
      <span actions class="meta">{{ bodyLength() }} caratteri</span>
      @if (draft(); as currentDraft) {
        <article class="draft">
          @if (hasCover(currentDraft)) {
            <img
              class="previewImage"
              [src]="currentDraft.coverImageUrl"
              [alt]="'Immagine di copertina della bozza: ' + currentDraft.title"
            />
          } @else if (isCoverPending(currentDraft)) {
            <mvp-empty-state>Generazione copertina in corso.</mvp-empty-state>
          } @else {
            <mvp-empty-state>Nessuna copertina per questa bozza.</mvp-empty-state>
          }

          @if (currentDraft.coverStatus === "failed" && currentDraft.coverError) {
            <p class="warning" role="status">{{ currentDraft.coverError }}</p>
          }

          @if (!isDiscarded(currentDraft)) {
            <div class="coverActions">
              <input
                #coverInput
                class="coverInput"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                [disabled]="isUpdatingCover()"
                (change)="onCoverSelected($event)"
              />
              <button mvpButton type="button" variant="secondary" [disabled]="isUpdatingCover()" (click)="coverInput.click()">
                Cambia immagine
              </button>
              <button
                mvpButton
                type="button"
                variant="secondary"
                [disabled]="isUpdatingCover() || !hasCover(currentDraft)"
                (click)="removeCover.emit()"
              >
                Rimuovi immagine
              </button>
            </div>
          }
          @if (saveError()) {
            <p class="errorNote">{{ saveError() }}</p>
          }
          <form id="draftForm" [formGroup]="form" [class.isEditing]="isEditing()" (ngSubmit)="save(currentDraft.id)">
            <label class="field">
              <span>Titolo</span>
              <input [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" formControlName="title" />
            </label>
            <label class="field">
              <span>Testo</span>
              <textarea
                rows="7"
                [readOnly]="!isEditing()"
                [attr.aria-readonly]="!isEditing()"
                formControlName="body"
              ></textarea>
            </label>
          </form>
          @if (isReadyForPreview(currentDraft)) {
            <div class="previewBlock">
              <h3 class="previewLabel">Documento finale</h3>
              <div class="previewActions">
                <a class="previewLink" [href]="currentDraft.previewUrl" target="_blank" rel="noreferrer">
                  Apri anteprima
                </a>
                <a class="previewLink downloadLink" [href]="currentDraft.exportUrl">Scarica PDF</a>
              </div>
            </div>
          }

          <div class="ratingSection">
            <h3 class="ratingLabel">Valuta questa bozza</h3>
            @if (!hasRated()) {
              <p class="ratingNote">La valutazione si assegna una volta sola.</p>
            }
            <mvp-star-rating
              [rating]="displayRating()"
              [disabled]="hasRated() || isRating()"
              [label]="hasRated() ? 'Punteggio assegnato' : 'Assegna un punteggio da 1 a 5 stelle'"
              (rated)="onStarsSelected($event)"
            ></mvp-star-rating>

            @if (!hasRated()) {
              <label class="field commentField">
                <span>Commento qualitativo (opzionale)</span>
                <textarea
                  rows="3"
                  [formControl]="commentControl"
                  placeholder="Commento alla valutazione"
                ></textarea>
                <span class="commentMeta" [class.isInvalid]="commentTooLong()">
                  {{ commentLength() }} / {{ commentMaxLength }}
                </span>
              </label>

              @if (commentTooLong()) {
                <p class="ratingError" role="alert">
                  Il commento supera la lunghezza massima consentita ({{ commentMaxLength }} caratteri).
                </p>
              }

              @if (localError(); as error) {
                <p class="ratingError" role="alert">{{ error }}</p>
              }

              @if (rateError(); as error) {
                <p class="ratingError" role="alert">{{ error }}</p>
              }

              <button
                mvpButton
                type="button"
                [disabled]="isRating() || !selectedRating() || commentTooLong()"
                [busy]="isRating()"
                (click)="submitRating()"
              >
                Invia valutazione
              </button>
            } @else {
              @if (currentDraft.ratingComment) {
                <p class="savedComment">
                  <span>Commento</span>
                  {{ currentDraft.ratingComment }}
                </p>
              }
              <p class="ratingSuccess">Valutazione registrata con successo.</p>
            }
          </div>

          <div class="footer">
            <mvp-status-badge>{{ currentDraft.status }}</mvp-status-badge>
            <span>Creato da AI Assistant{{ isEditing() ? "" : " · anteprima in sola lettura" }}</span>
          </div>
          <div class="actionBar">
            @if (isEditing()) {
              <button mvpButton variant="secondary" type="button" [disabled]="isSaving()" (click)="cancelEdit(currentDraft)">
                <svg lucideX aria-hidden="true"></svg>
                Annulla
              </button>
              <!-- Fuori dal form: l'attributo form lo lega comunque all'invio. -->
              <button mvpButton type="submit" form="draftForm" [disabled]="isSaving() || form.invalid" [busy]="isSaving()">
                <svg lucideSave aria-hidden="true"></svg>
                Salva
              </button>
            } @else if (!isDiscarded(currentDraft)) {
              <button mvpButton variant="secondary" type="button" (click)="startEdit(currentDraft)">
                <svg lucidePencil aria-hidden="true"></svg>
                Modifica
              </button>
              @if (!isApproved(currentDraft)) {
                <button
                  mvpButton
                  type="button"
                  [disabled]="isGenerating() || isDiscarding() || isSavingToHistory()"
                  [busy]="isSavingToHistory()"
                  (click)="saveToHistory.emit()"
                >
                  Salva nello storico
                </button>
              }
              <button
                mvpButton
                type="button"
                variant="secondary"
                [disabled]="isGenerating() || isDiscarding() || isSavingToHistory()"
                (click)="regenerate.emit()"
              >
                Rigenera bozza
              </button>
              @if (isConfirmingDiscard()) {
                <p class="warning" role="status">
                  Una bozza scartata non è più modificabile né rigenerabile.
                </p>
                <button mvpButton type="button" [disabled]="isDiscarding()" (click)="discard.emit()">
                  Conferma eliminazione
                </button>
                <button
                  mvpButton
                  type="button"
                  variant="secondary"
                  [disabled]="isDiscarding()"
                  (click)="isConfirmingDiscard.set(false)"
                >
                  Annulla
                </button>
              } @else {
                <button
                  mvpButton
                  type="button"
                  variant="secondary"
                  [disabled]="isGenerating() || isDiscarding()"
                  (click)="isConfirmingDiscard.set(true)"
                >
                  Scarta bozza
                </button>
              }
            }
          </div>
        </article>
      } @else {
        <mvp-empty-state>La bozza generata compare qui.</mvp-empty-state>
      }
    </mvp-section>
  `,
  styleUrls: [
    "../../../shared/styles/field.css",
    "../../../shared/styles/notice.css",
    "../../../shared/styles/link-button.css",
    "./generated-communication-preview.css"
  ]
})
export class GeneratedCommunicationPreviewComponent {
  readonly draft = input<GeneratedDraft | null>(null);
  readonly isUpdatingCover = input<boolean>(false);
  readonly isGenerating = input<boolean>(false);
  readonly isDiscarding = input<boolean>(false);
  readonly isSavingToHistory = input<boolean>(false);
  readonly isRating = input(false);
  readonly rateError = input<string | null>(null);
  readonly isSaving = input<boolean>(false);
  readonly saveError = input<string | null>(null);
  readonly uploadCover = output<File>();
  readonly removeCover = output<void>();
  readonly regenerate = output<void>();
  readonly discard = output<void>();
  readonly saveToHistory = output<void>();
  readonly rate = output<RateDraftPayload>();
  readonly saveRequested = output<{ communicationId: number; payload: UpdateCommunicationRequest }>();

  protected readonly commentMaxLength = RATING_COMMENT_MAX_LENGTH;
  protected readonly selectedRating = signal(0);
  protected readonly localError = signal<string | null>(null);
  protected readonly commentControl = new FormControl("", {
    nonNullable: true,
    validators: [Validators.maxLength(RATING_COMMENT_MAX_LENGTH)]
  });

  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);
  protected readonly isConfirmingDiscard = signal(false);
  protected readonly hasRated = computed(() => this.draft()?.rating != null);
  protected readonly displayRating = computed(
    () => this.draft()?.rating ?? this.selectedRating()
  );
  protected readonly commentLength = signal(0);
  protected readonly commentTooLong = computed(
    () => this.commentLength() > RATING_COMMENT_MAX_LENGTH
  );

  protected readonly isEditing = signal(false);
  protected readonly form = new FormGroup({
    title: new FormControl("", { nonNullable: true, validators: [Validators.required, Validators.maxLength(255)] }),
    body: new FormControl("", { nonNullable: true, validators: [Validators.required, Validators.maxLength(20000)] })
  });

  private readonly syncedDraftId = signal<number | null>(null);

  /** Ultimo contenuto sincronizzato: distingue un vero cambio bozza da un nuovo riferimento a contenuto identico. */
  private lastSyncedDraft: { id: number | null; title: string; body: string } | null = null;

  /** Ultimo id per cui il passo di conferma scarto e' stato chiuso, vedi il commento sull'effect qui sotto. */
  private lastConfirmDiscardDraftId: number | null = null;

  constructor() {
    this.commentControl.valueChanges.subscribe((value) => {
      this.commentLength.set(value.length);
      if (value.length <= RATING_COMMENT_MAX_LENGTH) {
        this.localError.set(null);
      }
    });

    // Si chiude solo su id diverso: un nuovo riferimento a parita' di id non deve
    // chiudere una conferma appena aperta.
    effect(() => {
      const draftId = this.draft()?.id ?? null;

      if (draftId === this.lastConfirmDiscardDraftId) {
        return;
      }

      this.lastConfirmDiscardDraftId = draftId;
      this.isConfirmingDiscard.set(false);
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

    // Confronta il contenuto, non il riferimento: un nuovo oggetto a parita' di
    // contenuto non deve toccare un form che l'utente sta scrivendo.
    effect(() => {
      const currentDraft = this.draft();
      const snapshot = { id: currentDraft?.id ?? null, title: currentDraft?.title ?? "", body: currentDraft?.body ?? "" };
      const unchanged =
        this.lastSyncedDraft !== null &&
        this.lastSyncedDraft.id === snapshot.id &&
        this.lastSyncedDraft.title === snapshot.title &&
        this.lastSyncedDraft.body === snapshot.body;

      if (unchanged) {
        return;
      }

      this.lastSyncedDraft = snapshot;
      this.form.setValue({ title: snapshot.title, body: snapshot.body });
      this.isEditing.set(false);
    });
  }

  protected isDiscarded(draft: GeneratedDraft): boolean {
    return draft.status === "Scartata";
  }

  protected isApproved(draft: GeneratedDraft): boolean {
    return draft.status === "Approvata";
  }

  protected isReadyForPreview(draft: GeneratedDraft): boolean {
    return draft.generationStatus === "completed" && draft.status !== "Scartata" && Boolean(draft.previewUrl);
  }

  protected hasCover(draft: GeneratedDraft): boolean {
    return Boolean(draft.coverImageUrl && draft.coverImageUrl.trim() !== "");
  }

  protected isCoverPending(draft: GeneratedDraft): boolean {
    return draft.coverStatus === "pending" || draft.coverStatus === "processing";
  }

  protected onCoverSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.item(0);

    if (!file) {
      return;
    }

    this.uploadCover.emit(file);
    input.value = "";
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

  protected startEdit(currentDraft: GeneratedDraft): void {
    this.form.setValue({ title: currentDraft.title, body: currentDraft.body });
    this.isEditing.set(true);
  }

  protected cancelEdit(currentDraft: GeneratedDraft): void {
    this.form.setValue({ title: currentDraft.title, body: currentDraft.body });
    this.isEditing.set(false);
  }

  protected save(communicationId: number): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saveRequested.emit({ communicationId, payload: this.form.getRawValue() });
  }
}
