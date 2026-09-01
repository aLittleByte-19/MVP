import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { ringDash } from "../../util/charts";
import { formatCount, NOT_AVAILABLE } from "../../util/metrics";

const SIZE = 76;
const STROKE = 10;
const RADIUS = (SIZE - STROKE) / 2;

/**
 * Scheda di quota: il resto della circonferenza non e' un grigio neutro, e' cio' che manca — il numero su cui l'operatore deve agire.
 * `restTone` distingue il residuo che chiede un intervento (rosso) da un semplice complemento di misura (grigio).
 */
@Component({
  selector: "mvp-metric-share",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dl class="card" [attr.aria-busy]="isLoading() ? 'true' : null">
      <dt class="label">{{ label() }}</dt>
      <dd class="value">
        @if (isLoading()) {
          <span class="skeleton num" aria-hidden="true"></span>
          <span class="srOnly">Caricamento in corso</span>
        } @else {
          <span class="figure">
            <svg class="ring" [attr.viewBox]="'0 0 ' + size + ' ' + size" role="img" [attr.aria-label]="summary()">
              <circle
                class="rest"
                [class.plain]="restTone() === 'neutral'"
                [attr.cx]="size / 2"
                [attr.cy]="size / 2"
                [attr.r]="radius"
                [attr.stroke-width]="stroke"
              />
              @if (dash(); as segments) {
                <circle
                  class="done"
                  [attr.cx]="size / 2"
                  [attr.cy]="size / 2"
                  [attr.r]="radius"
                  [attr.stroke-width]="stroke"
                  [attr.stroke-dasharray]="segments.filled + ' ' + segments.rest"
                  [attr.transform]="'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')'"
                />
              }
              <text class="percent" [attr.x]="size / 2" [attr.y]="size / 2" text-anchor="middle" dominant-baseline="central">
                {{ percentDisplay() }}
              </text>
            </svg>
            <span class="side">
              <b>{{ displayValue() }}</b> su {{ totalDisplay() }}
              @if (restLabel(); as rest) {
                <br /><span class="rest" [class.plain]="restTone() === 'neutral'">{{ rest }}</span>
              }
            </span>
          </span>
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
  /** Come si legge cio' che manca: un guasto da chiudere o un semplice resto. */
  readonly restTone = input<"alert" | "neutral">("alert");
  /** Nome di cio' che manca ("da verificare", "senza voto"). */
  readonly restNoun = input("da completare");
  readonly isLoading = input(false);

  protected readonly size = SIZE;
  protected readonly stroke = STROKE;
  protected readonly radius = RADIUS;

  protected readonly displayValue = computed(() => {
    const current = this.value();

    return current === null ? NOT_AVAILABLE : formatCount(current);
  });

  protected readonly totalDisplay = computed(() => {
    const total = this.total();

    return total === null ? NOT_AVAILABLE : formatCount(total);
  });

  /** Percentuale della parte a posto, `null` quando manca uno dei due termini. */
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
      return NOT_AVAILABLE;
    }

    // Sotto l'uno per cento l'arrotondamento a zero direbbe "nessuno" di
    // qualcosa che invece c'e'; sopra il novantanove, "100%" direbbe che non
    // manca piu' nulla mentre qualcosa manca ancora.
    if (percent > 0 && percent < 1) {
      return "<1%";
    }

    if (percent > 99 && percent < 100) {
      return ">99%";
    }

    return `${Math.round(percent)}%`;
  });

  protected readonly dash = computed(() => {
    const percent = this.percent();

    return percent === null ? null : ringDash(percent, RADIUS);
  });

  protected readonly restLabel = computed(() => {
    const value = this.value();
    const total = this.total();

    if (value === null || total === null || total - value <= 0) {
      return null;
    }

    return `${formatCount(total - value)} ${this.restNoun()}`;
  });

  protected readonly summary = computed(
    () => `${this.label()}: ${this.displayValue()} su ${this.totalDisplay()}`
  );
}
