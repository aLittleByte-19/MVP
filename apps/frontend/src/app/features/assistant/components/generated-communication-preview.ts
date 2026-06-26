import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../../layout/section/section";
import type { GeneratedDraft } from "../assistant.model";

@Component({
  selector: "poc-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EmptyStateComponent, SectionComponent, StatusBadgeComponent],
  template: `
    <poc-section id="assistant-review" title="Controlla il contenuto">
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
          <div class="footer">
            <poc-status-badge>{{ currentDraft.status }}</poc-status-badge>
            <span>Creato da AI Assistant · anteprima in sola lettura</span>
          </div>
        </article>
      } @else {
        <poc-empty-state>La bozza generata apparira qui.</poc-empty-state>
      }
    </poc-section>
  `,
  styleUrl: "./generated-communication-preview.css"
})
export class GeneratedCommunicationPreviewComponent {
  readonly draft = input<GeneratedDraft | null>(null);
  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);
}
