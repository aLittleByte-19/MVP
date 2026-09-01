import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import type { MetricTone } from "../../util/metrics";

/**
 * Scheda di misura su scala: un valore che vive dentro un intervallo noto, con una tacca per la soglia di decisione automatica.
 * Il valore arriva gia' formattato (`formatMetric`); `numeric` serve solo a posizionare il riempimento.
 */
@Component({
  selector: "mvp-metric-gauge",
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
              <span class="num">{{ value() }}</span>
              @if (unit(); as unitText) {
                <span class="unit">{{ unitText }}</span>
              }
            </span>
          }
        </span>
        @if (!isLoading()) {
          <span class="scale" aria-hidden="true">
            @if (thresholdAt(); as position) {
              <span class="thresholdRow">
                <span class="thresholdLabel" [style.left.%]="position">soglia {{ threshold() }}{{ unit() }}</span>
              </span>
            }
            <span class="track">
              @if (fill() !== null) {
                <span class="fill" [style.width.%]="fill()"></span>
              }
              @if (thresholdAt(); as position) {
                <span class="tick" [style.left.%]="position"></span>
              }
            </span>
            <span class="bounds">
              <span>{{ minLabel }}</span>
              <span>{{ maxLabel() }}{{ unit() }}</span>
            </span>
          </span>
          @if (context(); as contextText) {
            <span class="context">{{ contextText }}</span>
          }
        } @else {
          <span class="skeleton txt" aria-hidden="true"></span>
        }
      </dd>
    </dl>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-gauge.css"]
})
export class MetricGaugeComponent {
  readonly label = input.required<string>();
  /** Valore gia' formattato per la lettura ("98,5", "4,3", "—"). */
  readonly value = input.required<string>();
  /** Lo stesso valore come numero, per il riempimento; `null` se non c'e'. */
  readonly numeric = input<number | null>(null);
  readonly max = input(100);
  /** Soglia oltre la quale il sistema valida da solo, se la metrica ne ha una. */
  readonly threshold = input<number | null>(null);
  readonly unit = input<string | null>(null);
  readonly tone = input<MetricTone>("neutral");
  readonly context = input<string | null>(null);
  readonly isLoading = input(false);

  /* L'estremo destro porta l'unita', quello sinistro no: "0 100%" si legge
     come un intervallo, "0% 100%" come due misure separate. */
  protected readonly minLabel = "0";
  protected readonly maxLabel = computed(() => String(this.max()));

  protected readonly fill = computed(() => this.positionOf(this.numeric()));
  protected readonly thresholdAt = computed(() => this.positionOf(this.threshold()));

  private positionOf(value: number | null): number | null {
    const max = this.max();

    if (value === null || max <= 0) {
      return null;
    }

    return Math.min(100, Math.max(0, (value / max) * 100));
  }
}
