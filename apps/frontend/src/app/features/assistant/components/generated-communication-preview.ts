import { ChangeDetectionStrategy, Component, computed, input, output } from "@angular/core";
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
            <p class="cover-warning" role="status">{{ currentDraft.coverError }}</p>
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
          @if (isReadyForPreview(currentDraft)) {
            <div class="preview-block">
              <span class="preview-label">Documento finale</span>
              <div class="preview-actions">
                <a class="previewLink" [href]="currentDraft.previewUrl" target="_blank" rel="noreferrer">
                  Apri anteprima
                </a>
                <a class="previewLink downloadLink" [href]="currentDraft.exportUrl">Scarica PDF</a>
              </div>
            </div>
          }
          <div class="footer">
            <mvp-status-badge>{{ currentDraft.status }}</mvp-status-badge>
            <span>Pronta per la revisione</span>
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
  readonly uploadCover = output<File>();
  readonly removeCover = output<void>();
  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);

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
}
