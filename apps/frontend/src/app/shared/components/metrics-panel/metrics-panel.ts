import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import type { Metric } from "../../../../api/generated/model";
import { formatCount, formatMetric, type MetricTone, newToday } from "../../util/metrics";
import { EmptyStateComponent } from "../empty-state/empty-state";
import { MetricCardComponent } from "../metric-card/metric-card";

/** Tono e contesto assegnati a una metrica dalla pagina che la mostra. */
export interface MetricPresentation {
  readonly tone?: MetricTone;
  /** Totale su cui calcolare il rapporto, quando la metrica e' una quota. */
  readonly outOf?: number;
}

@Component({
  selector: "mvp-metrics-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EmptyStateComponent, MetricCardComponent],
  template: `
    @if (isLoading()) {
      <ul class="grid" [attr.aria-label]="ariaLabel()" aria-busy="true">
        @for (placeholder of placeholders; track placeholder) {
          <li>
            <mvp-metric-card label="Caricamento" [value]="null" [isLoading]="true" />
          </li>
        }
      </ul>
    } @else if (hasError()) {
      <p class="failed" role="status">Metriche non disponibili.</p>
    } @else if (metrics().length) {
      <ul class="grid" [attr.aria-label]="ariaLabel()">
        @for (entry of entries(); track entry.key) {
          <li>
            <mvp-metric-card
              [label]="entry.label"
              [value]="entry.value"
              [unit]="entry.unit"
              [tone]="entry.tone"
              [context]="entry.context"
              [history]="entry.history"
            />
          </li>
        }
      </ul>
    } @else {
      <mvp-empty-state>Nessuna metrica disponibile.</mvp-empty-state>
    }
  `,
  styleUrl: "./metrics-panel.css"
})
export class MetricsPanelComponent {
  readonly isLoading = input<boolean>(false);
  /**
   * Distinto da "nessuna metrica": in errore i dati esistono, e' la lettura ad
   * essere fallita, e mostrare "nessuna metrica" affermerebbe il falso.
   */
  readonly hasError = input<boolean>(false);
  readonly metrics = input<Metric[]>([]);
  readonly ariaLabel = input<string>("Metriche");
  /** Tono e rapporto per chiave, decisi dalla pagina che conosce il contesto. */
  readonly presentation = input<Record<string, MetricPresentation>>({});

  protected readonly placeholders = [0, 1, 2, 3];

  protected readonly entries = computed(() =>
    this.metrics().map((metric) => {
      const formatted = formatMetric(metric);
      const presentation = this.presentation()[metric.key];

      return {
        key: metric.key,
        label: metric.label,
        value: formatted.value,
        unit: formatted.unit,
        tone: presentation?.tone ?? ("neutral" as MetricTone),
        context: this.contextFor(metric, presentation),
        history: metric.history
      };
    })
  );

  /**
   * Riga di contesto: risponde a "e' tanto?" con il rapporto sul totale, e a
   * "sta crescendo?" con gli ingressi di oggi. La serie e' un flusso, quindi
   * si dice "nuovi oggi" e mai "rispetto a ieri" (vedi lo schema Metric).
   */
  private contextFor(metric: Metric, presentation?: MetricPresentation): string | null {
    const parts: string[] = [];
    const total = presentation?.outOf;

    if (total !== undefined && total > 0 && typeof metric.value === "number") {
      parts.push(`su ${formatCount(total)} totali`);
    }

    const today = newToday(metric.history);

    if (today !== null && today > 0) {
      parts.push(today === 1 ? "1 nuovo oggi" : `${today} nuovi oggi`);
    }

    return parts.length ? parts.join(" · ") : null;
  }
}
