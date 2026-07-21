import { ChangeDetectionStrategy, Component, computed, input, signal } from "@angular/core";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../../layout/section/section";
import { StarRating } from "../../../components/star-rating/star-rating";
import type { GeneratedDraft } from "../assistant.model";

@Component({
  selector: "mvp-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EmptyStateComponent, SectionComponent, StatusBadgeComponent, StarRating],
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
            <span>Valuta questa bozza:</span>
            <mvp-star-rating 
              [disabled]="hasRated()" 
              (rated)="onRate($event)">
            </mvp-star-rating>
          </div>
          @if (hasRated()) {
            <p>Valutazione registrata con successo!</p>
          }
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
  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);
  protected readonly hasRated = signal(false);

  protected onRate(stars: number): void {
    this.hasRated.set(true);
  }
}
