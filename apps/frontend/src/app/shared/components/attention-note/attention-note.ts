import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";

/** Gravita' della segnalazione: cambia glifo e colore, mai il solo colore. */
export type AttentionTone = "watch" | "alert";

/** Riga che segnala qualcosa su cui agire, con l'azione che la risolve. Va mostrata solo quando c'e' davvero un compito, altrimenti si smette di leggerla. */
@Component({
  selector: "mvp-attention-note",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="note" [class]="tone()" role="status">
      <span class="ic" aria-hidden="true">{{ tone() === "alert" ? "×" : "◆" }}</span>
      <span class="txt">
        <strong>{{ title() }}</strong>
        @if (detail(); as detailText) {
          <span>{{ detailText }}</span>
        }
      </span>
      @if (actionLabel(); as action) {
        <button type="button" class="act" (click)="acted.emit()">{{ action }}</button>
      }
    </div>
  `,
  styleUrl: "./attention-note.css"
})
export class AttentionNoteComponent {
  readonly title = input.required<string>();
  readonly detail = input<string | null>(null);
  readonly tone = input<AttentionTone>("watch");
  readonly actionLabel = input<string | null>(null);

  readonly acted = output<void>();
}
