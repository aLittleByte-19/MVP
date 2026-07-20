import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { SectionComponent } from "../../../layout/section/section";
import type { GeneratedDraft } from "../assistant.model";

@Component({
  selector: "mvp-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [SectionComponent, StatusBadgeComponent],
  template: `
    <mvp-section id="assistant-review" title="Controlla il contenuto">
      <span actions class="meta">{{ bodyLength() }} caratteri</span>
      @if (draft(); as currentDraft) {
        <article class="draft">
          <img
            class="preview-image"
            [src]="coverImageUrl(currentDraft)"
            [alt]="'Immagine generata per la bozza: ' + currentDraft.title"
          />
          @if (shouldShowCoverWarning(currentDraft)) {
            <p class="cover-warning">{{ currentDraft.coverImageWarning }}</p>
          }
          <label class="field">
            <span>Titolo</span>
            <input readOnly [value]="currentDraft.title" />
          </label>
          <label class="field">
            <span>Testo</span>
            <textarea readOnly rows="7" [value]="currentDraft.body"></textarea>
          </label>
          <div class="footer">
            <mvp-status-badge>{{ currentDraft.status }}</mvp-status-badge>
            <span>Creato da AI Assistant · anteprima in sola lettura</span>
          </div>
        </article>
      } @else {
        <article class="draft">
          <img class="preview-image" [src]="placeholderCover()" alt="Copertina generata in attesa della bozza" />
          <div class="footer">
            <mvp-status-badge>draft</mvp-status-badge>
            <span>In attesa dell'anteprima</span>
          </div>
        </article>
      }
    </mvp-section>
  `,
  styleUrl: "./generated-communication-preview.css"
})
export class GeneratedCommunicationPreviewComponent {
  readonly draft = input<GeneratedDraft | null>(null);
  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);

  protected placeholderCover(): string {
    return this.buildFallbackCover();
  }

  protected coverImageUrl(draft: GeneratedDraft): string {
    if (draft.coverImageUrl && draft.coverImageUrl.trim() !== "") {
      return draft.coverImageUrl;
    }

    return this.placeholderCover();
  }

  protected shouldShowCoverWarning(draft: GeneratedDraft): boolean {
    const missingCover = !draft.coverImageUrl || draft.coverImageUrl.trim() === "";
    const warning = draft.coverImageWarning?.trim() ?? "";

    return missingCover && warning.length > 0;
  }

  private buildFallbackCover(): string {
    const palette = this.pickPalette(this.hash("assistant-cover-placeholder"));

    const svg = `
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 320" role="img" aria-label="Copertina bozza AI">
        <defs>
          <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="${palette[0]}" />
            <stop offset="55%" stop-color="${palette[1]}" />
            <stop offset="100%" stop-color="${palette[2]}" />
          </linearGradient>
        </defs>
        <rect width="1200" height="320" fill="url(#bg)" />
        <circle cx="170" cy="88" r="120" fill="rgba(255,255,255,0.16)" />
        <circle cx="1040" cy="262" r="180" fill="rgba(255,255,255,0.12)" />
        <path d="M0 272 C180 210 390 300 560 248 C700 206 910 214 1200 280 L1200 320 L0 320 Z" fill="rgba(15,23,42,0.2)" />
        <circle cx="104" cy="100" r="16" fill="rgba(255,255,255,0.72)" />
        <circle cx="152" cy="100" r="10" fill="rgba(255,255,255,0.62)" />
        <circle cx="188" cy="100" r="6" fill="rgba(255,255,255,0.52)" />
      </svg>
    `;

    return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
  }

  private pickPalette(seed: number): readonly [string, string, string] {
    const palettes = [
      ["#0f766e", "#0e7490", "#22d3ee"],
      ["#1d4ed8", "#2563eb", "#93c5fd"],
      ["#7c2d12", "#ea580c", "#fdba74"],
      ["#3f6212", "#65a30d", "#bef264"],
      ["#6d28d9", "#7c3aed", "#c4b5fd"]
    ] as const;

    return palettes[seed % palettes.length];
  }

  private hash(value: string): number {
    let hash = 0;

    for (let index = 0; index < value.length; index += 1) {
      hash = (hash * 31 + value.charCodeAt(index)) >>> 0;
    }

    return hash;
  }
}
