import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { ringArcs } from "../../util/charts";
import { formatCount } from "../../util/metrics";

/** Una voce di una ripartizione: quanto vale e con che tono. */
export interface MetricPart {
  readonly label: string;
  readonly value: number;
}

const SIZE = 92;
const STROKE = 12;
const RADIUS = (SIZE - STROKE) / 2;

/**
 * Scheda di ripartizione: come si divide un insieme fra gli stati in cui si
 * trova.
 *
 * Sostituisce la quota a due colori ("94% verificati"): una percentuale sola
 * schiaccia in un unico numero cose che l'operatore tratta in modo diverso —
 * cio' che aspetta una revisione, cio' che il sistema ha validato da solo, cio'
 * che una persona ha confermato. L'anello le tiene distinte e la legenda dice
 * quante sono.
 *
 * I colori arrivano dal contratto, non da una tabella qui: gli stati di dominio
 * dichiarano gia' il proprio tono.
 */
@Component({
  selector: "mvp-metric-breakdown",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card" [attr.aria-busy]="isLoading() ? 'true' : null">
      <h3 class="label">{{ label() }}</h3>
      @if (isLoading()) {
        <span class="skeleton num" aria-hidden="true"></span>
        <span class="srOnly">Caricamento in corso</span>
      } @else if (arcs().length) {
        <div class="figure">
          <svg class="ring" [attr.viewBox]="'0 0 ' + size + ' ' + size" role="img" [attr.aria-label]="summary()">
            @for (arc of arcs(); track arc.label) {
              <circle
                [class]="arc.tone"
                [attr.cx]="size / 2"
                [attr.cy]="size / 2"
                [attr.r]="radius"
                [attr.stroke-width]="stroke"
                [attr.stroke-dasharray]="arc.dash"
                [attr.stroke-dashoffset]="arc.offset"
                [attr.transform]="'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')'"
              />
            }
            <text class="total" [attr.x]="size / 2" [attr.y]="size / 2" text-anchor="middle" dominant-baseline="central">
              {{ totalDisplay() }}
            </text>
          </svg>
          <ul class="legend">
            @for (arc of arcs(); track arc.label) {
              <li>
                <i [class]="arc.tone"></i>
                <span class="name">{{ arc.label }}</span>
                <b>{{ arc.display }}</b>
              </li>
            }
          </ul>
        </div>
      } @else {
        <p class="none">{{ emptyLabel() }}</p>
      }
    </section>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-breakdown.css"]
})
export class MetricBreakdownComponent {
  readonly label = input.required<string>();
  readonly parts = input<readonly MetricPart[] | undefined>(undefined);
  readonly emptyLabel = input("Nessun elemento da ripartire.");
  readonly isLoading = input(false);

  protected readonly size = SIZE;
  protected readonly stroke = STROKE;
  protected readonly radius = RADIUS;

  protected readonly arcs = computed(() =>
    ringArcs(this.parts() ?? [], RADIUS).map((arc) => ({
      ...arc,
      display: formatCount(arc.value)
    }))
  );

  protected readonly total = computed(() =>
    (this.parts() ?? []).reduce((sum, part) => sum + part.value, 0)
  );

  protected readonly totalDisplay = computed(() => formatCount(this.total()));

  protected readonly summary = computed(() => {
    const parts = this.arcs()
      .map((arc) => `${arc.label} ${arc.display}`)
      .join(", ");

    return `${this.label()} su ${this.totalDisplay()}: ${parts}`;
  });
}
