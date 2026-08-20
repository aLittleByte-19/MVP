import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { dailyBars } from "../../util/charts";
import { type MetricTone, NOT_AVAILABLE } from "../../util/metrics";

const WIDTH = 220;
const HEIGHT = 46;

/**
 * Scheda di andamento: un conteggio e i sette giorni che l'hanno prodotto.
 *
 * E' una delle forme di scheda metrica (vedi `metrics-panel`): questa risponde
 * a "quanto, e con che ritmo?". I giorni sono barre e non una linea perche' la
 * serie e' un flusso di ingressi — sette misure distinte, non un valore che
 * scorre — e una spezzata suggerirebbe fra un giorno e l'altro un passaggio
 * continuo che non esiste. L'ultima barra e' oggi, ed e' l'unica in evidenza.
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
