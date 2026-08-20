import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { type Segment, segments } from "../../util/charts";

/** Una fase della pipeline, con quanto tempo si prende. */
export interface MetricPart {
  readonly label: string;
  readonly value: number;
}

/**
 * Scheda di un tempo medio, ripartito fra le fasi che lo consumano.
 *
 * "Quarantasette secondi" e' un numero su cui non si puo' agire; "ventotto di
 * OCR, quindici di estrazione, quattro di attesa" dice dove intervenire. La
 * barra e la legenda sono la stessa informazione in due forme — la proporzione
 * e i secondi — perche' a colpo d'occhio serve la prima e per decidere la
 * seconda.
 *
 * Senza fasi la scheda resta un tempo medio e basta: succede quando le corse
 * concluse non hanno ancora lasciato traccia dei singoli passi.
 */
@Component({
  selector: "mvp-metric-phases",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dl class="card" [attr.aria-busy]="isLoading() ? 'true' : null">
      <dt class="label">{{ label() }}</dt>
      <dd class="value">
        <span class="figure">
          @if (isLoading()) {
            <span class="skeleton num" aria-hidden="true"></span>
            <span class="srOnly">Caricamento in corso</span>
          } @else {
            <span class="lead">
              <span class="num">{{ value() }}</span>
              @if (unit(); as unitText) {
                <span class="unit">{{ unitText }}</span>
              }
            </span>
          }
        </span>
        @if (!isLoading() && slices().length) {
          <span class="bar" role="img" [attr.aria-label]="summary()">
            @for (slice of slices(); track slice.label) {
              <span
                class="seg"
                [style.width.%]="slice.percent"
                [style.background]="'var(--mvp-series-' + slice.tone + ')'"
              ></span>
            }
          </span>
          <span class="legend" aria-hidden="true">
            @for (slice of slices(); track slice.label) {
              <span class="item">
                <i [style.background]="'var(--mvp-series-' + slice.tone + ')'"></i>{{ slice.label }}
                <b>{{ slice.value }}{{ unit() }}</b>
              </span>
            }
          </span>
        }
      </dd>
    </dl>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-phases.css"]
})
export class MetricPhasesComponent {
  readonly label = input.required<string>();
  /** Il tempo complessivo, gia' formattato per la lettura. */
  readonly value = input.required<string>();
  readonly unit = input<string | null>(null);
  readonly parts = input<readonly MetricPart[] | undefined>(undefined);
  readonly isLoading = input(false);

  protected readonly slices = computed<Segment[]>(() => segments(this.parts() ?? []));

  protected readonly summary = computed(() => {
    const parts = this.slices()
      .map((slice) => `${slice.label} ${slice.value}${this.unit() ?? ""}`)
      .join(", ");

    return `${this.label()}: ${parts}`;
  });
}
