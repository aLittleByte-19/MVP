import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import type { StatusTone } from "../../util/status";

/** Glifo per tono: una forma oltre al colore, non un'icona decorativa. */
const GLYPHS: Record<StatusTone, string> = {
  neutral: "",
  info: "◆",
  success: "✓",
  warning: "!",
  danger: "×"
};

/**
 * Etichetta di stato.
 *
 * Il testo porta già l'informazione ("Da revisionare", "Inviato"), quindi il
 * colore non è l'unico veicolo; il glifo aggiunge una **forma** distinta per
 * tono, che sopravvive alla stampa in bianco e nero e alle discromatopsie. È
 * marcato `aria-hidden`: chi usa uno screen reader riceve già il testo, e
 * annunciare "per cento" o "croce" sarebbe rumore.
 */
@Component({
  selector: "mvp-status-badge",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span
    class="badge"
    [class.info]="tone() === 'info'"
    [class.success]="tone() === 'success'"
    [class.warning]="tone() === 'warning'"
    [class.danger]="tone() === 'danger'"
    >@if (glyph()) {<span class="glyph" aria-hidden="true">{{ glyph() }}</span>}<ng-content
  /></span>`,
  styleUrl: "./status-badge.css"
})
export class StatusBadgeComponent {
  readonly tone = input<StatusTone>("neutral");

  protected readonly glyph = computed(() => GLYPHS[this.tone()]);
}
