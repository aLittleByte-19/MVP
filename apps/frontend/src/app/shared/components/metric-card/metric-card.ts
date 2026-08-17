import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { type MetricTone, NOT_AVAILABLE, sparklineEnd, sparklinePoints } from "../../util/metrics";

const SPARK_WIDTH = 96;
const SPARK_HEIGHT = 30;

/**
 * Scheda di una singola metrica.
 *
 * Coppia `dl/dt/dd` invece di due elementi affiancati: lo screen reader legge
 * "Documenti analizzati: 128" come una cosa sola, mentre prima riceveva due
 * stringhe scollegate. L'ordine nel DOM e' etichetta -> valore, quello di
 * lettura; l'inversione visiva sta nel CSS, cosi' la resa non cambia.
 *
 * Il valore `null` e' distinto dallo zero: durante il caricamento e in errore
 * la scheda non mostra un numero, perche' uno zero verrebbe letto come dato
 * reale (era il difetto dei tre KPI dell'Overview).
 */
@Component({
  selector: "mvp-metric-card",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dl class="card" [class]="tone()" [attr.aria-busy]="isLoading() ? 'true' : null">
      <dt class="label">{{ label() }}</dt>
      <dd class="value">
        @if (isLoading()) {
          <span class="skeleton num" aria-hidden="true"></span>
          <span class="sr-only">Caricamento in corso</span>
        } @else {
          <span class="num">{{ displayValue() }}</span>
          @if (unit(); as unitText) {
            <span class="unit">{{ unitText }}</span>
          }
          @if (sparkPoints(); as points) {
            <svg
              class="spark"
              [attr.viewBox]="'0 0 ' + sparkWidth + ' ' + sparkHeight"
              role="img"
              [attr.aria-label]="sparkLabel()"
            >
              <polyline
                [attr.points]="points"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
              @if (sparkTip(); as tip) {
                <circle [attr.cx]="tip.x" [attr.cy]="tip.y" r="2.8" fill="currentColor" />
              }
            </svg>
          }
        }
      </dd>
      @if (isLoading()) {
        <span class="skeleton txt" aria-hidden="true"></span>
      } @else if (context(); as contextText) {
        <span class="context">{{ contextText }}</span>
      }
    </dl>
  `,
  styleUrl: "./metric-card.css"
})
export class MetricCardComponent {
  readonly label = input.required<string>();
  readonly value = input.required<string | number | null>();
  readonly unit = input<string | null>(null);
  readonly tone = input<MetricTone>("neutral");
  /** Riga sotto il numero: rapporto sul totale, elementi nuovi oggi, o l'errore. */
  readonly context = input<string | null>(null);
  /** Flusso giornaliero degli ultimi sette giorni, quando la metrica ne ha uno. */
  readonly history = input<readonly number[] | undefined>(undefined);
  readonly isLoading = input(false);

  protected readonly sparkWidth = SPARK_WIDTH;
  protected readonly sparkHeight = SPARK_HEIGHT;

  protected readonly displayValue = computed(() => {
    const current = this.value();

    return current === null || current === "" ? NOT_AVAILABLE : String(current);
  });

  protected readonly sparkPoints = computed(() =>
    sparklinePoints(this.history(), SPARK_WIDTH, SPARK_HEIGHT)
  );

  protected readonly sparkTip = computed(() =>
    sparklineEnd(this.history(), SPARK_WIDTH, SPARK_HEIGHT)
  );

  protected readonly sparkLabel = computed(() => {
    const series = this.history();

    return series === undefined
      ? ""
      : `Andamento degli ultimi ${series.length} giorni per ${this.label()}`;
  });
}
