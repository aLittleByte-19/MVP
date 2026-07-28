import { ChangeDetectionStrategy, Component, computed, effect, input, output, signal } from "@angular/core";
import { ButtonComponent } from "../../../shared/components/button/button";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../../layout/section/section";
import type { GeneratedDraft } from "../assistant.model";

@Component({
  selector: "mvp-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, EmptyStateComponent, SectionComponent, StatusBadgeComponent],
  template: `
    <mvp-section id="assistant-review" title="Controlla il contenuto">
      <span actions class="meta">{{ bodyLength() }} caratteri</span>
      @if (draft(); as currentDraft) {
        <article class="draft">
          @if (hasCover(currentDraft)) {
            <img
              class="preview-image"
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

          <div class="cover-actions">
            <input
              #coverInput
              class="cover-input"
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
          <label class="field">
            <span>Titolo</span>
            <input type="text" [value]="currentDraft.title" readonly />
          </label>
          <label class="field">
            <span>Corpo</span>
            <textarea rows="8" [value]="currentDraft.body" readonly></textarea>
          </label>
          <div class="footer">
            <mvp-status-badge>{{ currentDraft.status }}</mvp-status-badge>
            <span>Pronta per la revisione</span>
          </div>
          <div class="review-actions">
            @if (!isDiscarded(currentDraft)) {
              <button
                mvpButton
                type="button"
                variant="secondary"
                [disabled]="isGenerating() || isDiscarding()"
                (click)="regenerate.emit()"
              >
                Rigenera bozza
              </button>
              @if (isConfirmingDiscard()) {
                <p class="warning" role="status">
                  Sei sicuro di voler scartare questa bozza? Non sara' piu' modificabile ne' rigenerabile.
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
        <mvp-empty-state>La bozza generata apparira qui.</mvp-empty-state>
      }
    </mvp-section>
  `,
  styleUrl: "./generated-communication-preview.css"
})
export class GeneratedCommunicationPreviewComponent {
  readonly draft = input<GeneratedDraft | null>(null);
  readonly isUpdatingCover = input<boolean>(false);
  readonly isGenerating = input<boolean>(false);
  readonly isDiscarding = input<boolean>(false);
  readonly uploadCover = output<File>();
  readonly removeCover = output<void>();
  readonly regenerate = output<void>();
  readonly discard = output<void>();
  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);
  protected readonly isConfirmingDiscard = signal(false);

  constructor() {
    // Il passo di conferma e' locale alla bozza mostrata: qualunque
    // cambiamento della bozza in anteprima (nuova selezione, aggiornamento
    // durante una rigenerazione, ecc.) non deve lasciarlo aperto.
    effect(() => {
      this.draft();
      this.isConfirmingDiscard.set(false);
    });
  }

  protected isDiscarded(draft: GeneratedDraft): boolean {
    return draft.status === "Scartata";
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
}
