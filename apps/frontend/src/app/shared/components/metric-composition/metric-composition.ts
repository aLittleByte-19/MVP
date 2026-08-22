import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { formatCount } from "../../util/metrics";

/** Una parte della composizione: etichetta, valore e posizione nella scala. */
export interface CompositionSlice {
  readonly label: string;
  readonly value: number;
}

/**
 * Barra segmentata di una partizione completa.
 *
 * Serve dove i conteggi affiancati nascondono le proporzioni: gli stati di
 * revisione o di invio sono una partizione dei sotto-documenti, quindi la
 * domanda utile non e' "quanti sono da verificare" (gia' risposta da una
 * scheda) ma "quanta parte del totale rappresentano".
 *
 * Le fette usano una scala monocromatica derivata dal colore primario, non
 * colori di stato: il verde e il rosso qui suggerirebbero un giudizio che una
 * composizione non esprime.
 */
@Component({
  selector: "mvp-metric-composition",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (total() > 0) {
      <div class="bar" role="img" [attr.aria-label]="summary()">
        @for (slice of slices(); track slice.label) {
          <span
            class="seg"
            [style.width.%]="slice.percent"
            [style.background]="'var(--mvp-series-' + slice.index + ')'"
          ></span>
        }
      </div>
      <ul class="legend">
        @for (slice of slices(); track slice.label) {
          <li>
            <span class="sw" [style.background]="'var(--mvp-series-' + slice.index + ')'"></span>
            <span class="name">{{ slice.label }}</span>
            <strong class="n">{{ slice.display }}</strong>
          </li>
        }
      </ul>
    } @else {
      <p class="none">{{ emptyLabel() }}</p>
    }
  `,
  styleUrl: "./metric-composition.css"
})
export class MetricCompositionComponent {
  readonly parts = input.required<readonly CompositionSlice[]>();
  readonly emptyLabel = input("Nessun elemento da ripartire.");
  /** Nome della grandezza ripartita, usato nella descrizione accessibile. */
  readonly subject = input("elementi");

  protected readonly total = computed(() =>
    this.parts().reduce((sum, slice) => sum + slice.value, 0)
  );

  protected readonly slices = computed(() => {
    const total = this.total();

    return this.parts()
      .filter((slice) => slice.value > 0)
      .map((slice, index) => ({
        label: slice.label,
        percent: (slice.value / total) * 100,
        display: formatCount(slice.value),
        // Cinque tonalita' disponibili: oltre, la barra smette di essere
        // leggibile e la tabella e' lo strumento giusto.
        index: Math.min(index + 1, 5)
      }));
  });

  protected readonly summary = computed(() => {
    const parts = this.slices()
      .map((slice) => `${slice.label} ${slice.display}`)
      .join(", ");

    return `Ripartizione di ${formatCount(this.total())} ${this.subject()}: ${parts}`;
  });
}
