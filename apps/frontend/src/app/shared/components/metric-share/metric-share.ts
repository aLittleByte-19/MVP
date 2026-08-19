import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { formatCount, type MetricTone, NOT_AVAILABLE } from "../../util/metrics";

/**
 * Scheda di quota: quanta parte di un totale.
 *
 * "23" e "23 su 412" rispondono a due domande diverse, e la seconda non si
 * legge da un numero grande: serve vedere la porzione. Prima il rapporto era
 * un "/412" accanto al valore dentro la scheda di andamento, cioe' la stessa
 * forma per un conteggio e per una quota.
 *
 * La barra e' `aria-hidden` e il rapporto viene detto per esteso nel testo:
 * ripeterlo come immagine con etichetta darebbe due annunci per lo stesso
 * dato.
 */
@Component({
  selector: "mvp-metric-share",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dl class="card" [class]="tone()" [attr.aria-busy]="isLoading() ? 'true' : null">
      <dt class="label">{{ label() }}</dt>
      <dd class="value">
        <span class="figure">
          @if (isLoading()) {
            <span class="skeleton num" aria-hidden="true"></span>
            <span class="srOnly">Caricamento in corso</span>
          } @else {
            <span class="lead">
              <span class="num">{{ displayValue() }}</span>
              <span class="total"
                ><span class="srOnly">su </span><span aria-hidden="true">/</span>{{ totalDisplay() }}</span
              >
            </span>
            @if (percentDisplay(); as percent) {
              <span class="percent">{{ percent }}</span>
            }
          }
        </span>
        @if (!isLoading() && percent() !== null) {
          <span class="bar" aria-hidden="true">
            <span class="fill" [style.width.%]="percent()"></span>
          </span>
        }
        @if (isLoading()) {
          <span class="skeleton txt" aria-hidden="true"></span>
        } @else if (context(); as contextText) {
          <span class="context">{{ contextText }}</span>
        }
      </dd>
    </dl>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-share.css"]
})
export class MetricShareComponent {
  readonly label = input.required<string>();
  readonly value = input.required<number | null>();
  readonly total = input.required<number | null>();
  readonly tone = input<MetricTone>("neutral");
  readonly context = input<string | null>(null);
  readonly isLoading = input(false);

  protected readonly displayValue = computed(() => {
    const current = this.value();

    return current === null ? NOT_AVAILABLE : formatCount(current);
  });

  protected readonly totalDisplay = computed(() => {
    const total = this.total();

    return total === null ? NOT_AVAILABLE : formatCount(total);
  });

  /** Percentuale di riempimento, `null` quando manca uno dei due termini. */
  protected readonly percent = computed(() => {
    const value = this.value();
    const total = this.total();

    if (value === null || total === null || total <= 0) {
      return null;
    }

    return Math.min(100, Math.max(0, (value / total) * 100));
  });

  protected readonly percentDisplay = computed(() => {
    const percent = this.percent();

    if (percent === null) {
      return null;
    }

    // Sotto l'uno per cento l'arrotondamento a zero direbbe "nessuno" di
    // qualcosa che invece c'e'.
    const rounded = percent > 0 && percent < 1 ? "<1" : String(Math.round(percent));

    return `${rounded}%`;
  });
}
