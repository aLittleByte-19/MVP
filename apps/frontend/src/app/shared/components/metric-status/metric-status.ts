import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { LucideCircleCheck, LucideImage, LucideTriangleAlert } from "@lucide/angular";
import { formatCount } from "../../util/metrics";

/**
 * Scheda di stato: un conteggio di guasti, dove conta prima il verdetto.
 *
 * Corse fallite e corse ferme oltre il tempo previsto non si leggono come gli
 * altri numeri: quasi sempre valgono zero, e uno zero grande quanto "412
 * documenti analizzati" chiede di fermarsi per capire che qui e' la notizia
 * buona. Il numero sta quindi dentro la frase — "2 da ricaricare", "nessuna in
 * ritardo" — accanto a un'icona che ne porta la forma, non il solo colore.
 *
 * `issueLabel` e `okLabel` appartengono alla metrica, non alla scheda: "2 da
 * riesaminare" e "2 senza copertina" chiedono due azioni diverse.
 */
@Component({
  selector: "mvp-metric-status",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [LucideCircleCheck, LucideImage, LucideTriangleAlert],
  template: `
    <section class="card" [class]="tone()" [attr.aria-busy]="isLoading() ? 'true' : null">
      <h3 class="label">{{ label() }}</h3>
      @if (isLoading()) {
        <span class="skeleton num" aria-hidden="true"></span>
        <span class="srOnly">Caricamento in corso</span>
      } @else {
        <p class="verdict">
          @switch (glyph()) {
            @case ("alert") {
              <svg lucideTriangleAlert aria-hidden="true"></svg>
            }
            @case ("image") {
              <svg lucideImage aria-hidden="true"></svg>
            }
            @default {
              <svg lucideCircleCheck aria-hidden="true"></svg>
            }
          }
          <span>{{ verdict() }}</span>
        </p>
        @if (context(); as contextText) {
          <p class="context">{{ contextText }}</p>
        }
      }
    </section>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-status.css"]
})
export class MetricStatusComponent {
  readonly label = input.required<string>();
  readonly value = input.required<number | null>();
  /** Verdetto quando il conteggio e' a zero. */
  readonly okLabel = input("Nessun caso");
  /**
   * Che cosa farne quando ce n'e' almeno uno. Il conteggio viene messo davanti
   * dalla scheda: qui va la sola azione ("da ricaricare").
   */
  readonly issueLabel = input("da esaminare");
  /** Rilievo del caso positivo: un guasto blocca, un degrado no. */
  readonly issueTone = input<"danger" | "warning">("danger");
  readonly context = input<string | null>(null);
  readonly isLoading = input(false);

  protected readonly hasIssues = computed(() => (this.value() ?? 0) > 0);

  protected readonly verdict = computed(() => {
    const current = this.value();

    if (current === null) {
      return "Non disponibile";
    }

    return this.hasIssues() ? `${formatCount(current)} ${this.issueLabel()}` : this.okLabel();
  });

  protected readonly glyph = computed(() => {
    if (this.value() === null || !this.hasIssues()) {
      return "ok";
    }

    return this.issueTone() === "warning" ? "image" : "alert";
  });

  protected readonly tone = computed(() => {
    if (this.value() === null) {
      return "neutral";
    }

    if (!this.hasIssues()) {
      return "ok";
    }

    return this.issueTone() === "danger" ? "alert" : "watch";
  });
}
