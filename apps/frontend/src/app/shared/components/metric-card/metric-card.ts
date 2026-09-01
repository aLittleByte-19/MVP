import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { dailyBars } from "../../util/charts";
import { type MetricTone, NOT_AVAILABLE } from "../../util/metrics";

const WIDTH = 220;
const HEIGHT = 46;

/**
 * Scheda di andamento: un conteggio e i sette giorni che l'hanno prodotto, come barre (flusso di ingressi distinti, non una curva continua).
 * Coppia `dl/dt/dd` cosi' lo screen reader legge etichetta e valore come una cosa sola. Il `null` resta distinto dallo zero: durante caricamento/errore uno zero sarebbe letto come dato reale.
 */
@Component({
  selector: "mvp-metric-card",
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
              @if (unit(); as unitText) {
                <span class="unit">{{ unitText }}</span>
              }
            </span>
            @if (context(); as contextText) {
              <span class="context">{{ contextText }}</span>
            }
          }
        </span>
        @if (isLoading()) {
          <span class="skeleton chart" aria-hidden="true"></span>
        } @else if (bars().length) {
          <svg
            class="chart"
            [attr.viewBox]="'0 0 ' + width + ' ' + height"
            preserveAspectRatio="none"
            role="img"
            [attr.aria-label]="chartLabel()"
          >
            @for (bar of bars(); track $index) {
              <rect
                [class.today]="bar.isLast"
                [attr.x]="bar.x"
                [attr.y]="bar.y"
                [attr.width]="bar.width"
                [attr.height]="bar.height"
                rx="2"
              />
            }
          </svg>
        }
      </dd>
    </dl>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-card.css"]
})
export class MetricCardComponent {
  readonly label = input.required<string>();
  readonly value = input.required<string | number | null>();
  readonly unit = input<string | null>(null);
  readonly tone = input<MetricTone>("neutral");
  /** Riga accanto al numero: gli ingressi di oggi. */
  readonly context = input<string | null>(null);
  /** Flusso giornaliero degli ultimi sette giorni, quando la metrica ne ha uno. */
  readonly history = input<readonly number[] | undefined>(undefined);
  readonly isLoading = input(false);

  protected readonly width = WIDTH;
  protected readonly height = HEIGHT;

  protected readonly displayValue = computed(() => {
    const current = this.value();

    return current === null || current === "" ? NOT_AVAILABLE : String(current);
  });

  protected readonly bars = computed(() => dailyBars(this.history() ?? [], WIDTH, HEIGHT));

  protected readonly chartLabel = computed(() => {
    const series = this.history();

    return series === undefined
      ? ""
      : `Andamento degli ultimi ${series.length} giorni per ${this.label()}`;
  });
}
