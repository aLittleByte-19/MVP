import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { formatCount, NOT_AVAILABLE } from "../../util/metrics";
import { StatusBadgeComponent } from "../status-badge/status-badge";

/**
 * Scheda di stato: un conteggio di guasti, dove conta prima il verdetto.
 *
 * Corse fallite e corse ferme oltre il tempo previsto non si leggono come gli
 * altri numeri: quasi sempre valgono zero, e uno zero grande quanto "412
 * documenti analizzati" chiede di fermarsi a capire che qui e' la notizia
 * buona. La pastiglia dice l'esito in parole — e con una forma, non con il
 * solo colore — e il numero resta accanto per chi deve sapere quanti.
 *
 * `issueLabel` e `okLabel` appartengono alla metrica, non alla scheda: "2 da
 * riesaminare" e "2 senza copertina" chiedono due azioni diverse.
 */
@Component({
  selector: "mvp-metric-status",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [StatusBadgeComponent],
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
            </span>
            <mvp-status-badge [tone]="badgeTone()">{{ verdict() }}</mvp-status-badge>
          }
        </span>
        @if (isLoading()) {
          <span class="skeleton txt" aria-hidden="true"></span>
        } @else if (context(); as contextText) {
          <span class="context">{{ contextText }}</span>
        }
      </dd>
    </dl>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-status.css"]
})
export class MetricStatusComponent {
  readonly label = input.required<string>();
  readonly value = input.required<number | null>();
  /** Verdetto quando il conteggio e' a zero. */
  readonly okLabel = input("Nessun caso");
  /** Verdetto quando ce n'e' almeno uno: dice che cosa farne. */
  readonly issueLabel = input("Da esaminare");
  /** Rilievo del caso positivo: un guasto e' `danger`, un degrado `warning`. */
  readonly issueTone = input<"danger" | "warning">("danger");
  readonly context = input<string | null>(null);
  readonly isLoading = input(false);

  protected readonly hasIssues = computed(() => (this.value() ?? 0) > 0);

  protected readonly displayValue = computed(() => {
    const current = this.value();

    return current === null ? NOT_AVAILABLE : formatCount(current);
  });

  protected readonly verdict = computed(() =>
    this.value() === null ? "Non disponibile" : this.hasIssues() ? this.issueLabel() : this.okLabel()
  );

  protected readonly badgeTone = computed(() => {
    if (this.value() === null) {
      return "neutral" as const;
    }

    return this.hasIssues() ? this.issueTone() : ("success" as const);
  });

  /** La striscia della scheda segue lo stesso verdetto della pastiglia. */
  protected readonly tone = computed(() => {
    if (this.value() === null) {
      return "neutral";
    }

    return this.hasIssues() ? (this.issueTone() === "danger" ? "alert" : "watch") : "ok";
  });
}
