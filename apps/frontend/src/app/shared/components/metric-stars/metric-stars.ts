import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { starFills } from "../../util/charts";

/**
 * Scheda di una media su stelle.
 *
 * Il numero da solo — "4,3" — chiede di ricordarsi che la scala arriva a
 * cinque; le stelle la mostrano. L'ultima si riempie per la frazione reale,
 * non arrotondata: e' proprio quel resto a distinguere un 4,3 da un 4,5, e
 * arrotondarlo butterebbe via l'unica cifra che stiamo misurando.
 *
 * Le stelle sono decorative (`aria-hidden`): il valore e' gia' scritto accanto
 * come testo, e farle leggere una per una sarebbe rumore.
 */
@Component({
  selector: "mvp-metric-stars",
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
              <span class="unit">/ {{ max() }}</span>
            </span>
          }
        </span>
        @if (!isLoading() && numeric() !== null) {
          <span class="stars" aria-hidden="true">
            @for (fill of fills(); track $index) {
              <svg class="star" viewBox="0 0 24 24">
                <defs>
                  <clipPath [attr.id]="clipId($index)">
                    <rect x="0" y="0" [attr.width]="24 * fill" height="24" />
                  </clipPath>
                </defs>
                <path class="outline" [attr.d]="starPath" />
                <path class="fill" [attr.d]="starPath" [attr.clip-path]="'url(#' + clipId($index) + ')'" />
              </svg>
            }
          </span>
        }
        @if (context(); as contextText) {
          <span class="context">{{ contextText }}</span>
        }
      </dd>
    </dl>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-stars.css"]
})
export class MetricStarsComponent {
  readonly label = input.required<string>();
  /** Valore gia' formattato ("4,3" oppure il segnaposto). */
  readonly value = input.required<string>();
  /** Lo stesso valore come numero, per riempire le stelle; `null` se non c'e'. */
  readonly numeric = input<number | null>(null);
  readonly max = input(5);
  readonly context = input<string | null>(null);
  readonly isLoading = input(false);

  /** Il tracciato della stella di Lucide, disegnato due volte: vuota e piena. */
  protected readonly starPath =
    "M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 " +
    ".294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 " +
    "2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 " +
    "9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z";

  protected readonly fills = computed(() => starFills(this.numeric() ?? 0, this.max()));

  /**
   * Gli id delle maschere devono essere unici nella pagina: due schede a stelle
   * con lo stesso id farebbero riferire entrambe alla prima maschera, e la
   * seconda mostrerebbe il riempimento della prima.
   */
  private readonly instance = Math.random().toString(36).slice(2, 8);

  protected clipId(index: number): string {
    return `star-${this.instance}-${index}`;
  }
}
