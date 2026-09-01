import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import type { Metric } from "../../../../api/generated/model";
import { formatMetric, type MetricTone, newToday } from "../../util/metrics";
import { EmptyStateComponent } from "../empty-state/empty-state";
import { MetricBreakdownComponent } from "../metric-breakdown/metric-breakdown";
import { MetricCardComponent } from "../metric-card/metric-card";
import { MetricGaugeComponent } from "../metric-gauge/metric-gauge";
import { MetricShareComponent } from "../metric-share/metric-share";
import { MetricStarsComponent } from "../metric-stars/metric-stars";
import { MetricStatusComponent } from "../metric-status/metric-status";

/** Forma di scheda con cui rendere una metrica: non estetica, ma il tipo di domanda a cui il numero risponde (andamento, quota, scala, ripartizione, voto, stato). */
export type MetricKind = "trend" | "share" | "gauge" | "status" | "breakdown" | "stars";

/** Come la pagina che conosce il contesto vuole che una metrica sia resa. */
export interface MetricPresentation {
  readonly kind?: MetricKind;
  readonly tone?: MetricTone;
  /** Quante colonne occupa la scheda: le forme con una legenda da leggere hanno bisogno di piu' larghezza. */
  readonly span?: 1 | 2;
  /** Estremo della scala (`gauge`); di default cento. */
  readonly max?: number;
  /** Come si legge cio' che manca alla quota (`share`). */
  readonly restTone?: "alert" | "neutral";
  readonly restNoun?: string;
  /** Verdetto a zero e azione da fare quando ce n'e' almeno uno (`status`). */
  readonly okLabel?: string;
  readonly issueLabel?: string;
  /** Un guasto blocca (`danger`), un degrado no (`warning`). */
  readonly issueTone?: "danger" | "warning";
}

@Component({
  selector: "mvp-metrics-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EmptyStateComponent,
    MetricBreakdownComponent,
    MetricCardComponent,
    MetricGaugeComponent,
    MetricShareComponent,
    MetricStarsComponent,
    MetricStatusComponent
  ],
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
          <li [class.wide]="entry.span === 2">
            @switch (entry.kind) {
              @case ("share") {
                <mvp-metric-share
                  [label]="entry.label"
                  [value]="entry.numeric"
                  [total]="entry.outOf"
                  [restTone]="entry.restTone"
                  [restNoun]="entry.restNoun"
                />
              }
              @case ("gauge") {
                <mvp-metric-gauge
                  [label]="entry.label"
                  [value]="entry.value"
                  [numeric]="entry.numeric"
                  [max]="entry.max"
                  [threshold]="entry.threshold"
                  [unit]="entry.unit"
                  [tone]="entry.tone"
                  [context]="entry.context"
                />
              }
              @case ("status") {
                <mvp-metric-status
                  [label]="entry.label"
                  [value]="entry.numeric"
                  [okLabel]="entry.okLabel"
                  [issueLabel]="entry.issueLabel"
                  [issueTone]="entry.issueTone"
                  [context]="entry.context"
                />
              }
              @case ("breakdown") {
                <mvp-metric-breakdown [label]="entry.label" [parts]="entry.parts" />
              }
              @case ("stars") {
                <mvp-metric-stars
                  [label]="entry.label"
                  [value]="entry.value"
                  [numeric]="entry.numeric"
                  [max]="entry.max"
                  [context]="entry.context"
                />
              }
              @default {
                <mvp-metric-card
                  [label]="entry.label"
                  [value]="entry.value"
                  [unit]="entry.unit"
                  [tone]="entry.tone"
                  [context]="entry.context"
                  [history]="entry.history"
                />
              }
            }
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
  /** Forma, rilievo e ingombro per chiave, decisi dalla pagina che conosce il contesto. */
  readonly presentation = input<Record<string, MetricPresentation>>({});

  protected readonly placeholders = [0, 1, 2, 3];

  protected readonly entries = computed(() =>
    this.metrics().map((metric) => {
      const formatted = formatMetric(metric);
      const presentation = this.presentation()[metric.key];

      return {
        key: metric.key,
        kind: presentation?.kind ?? ("trend" as MetricKind),
        span: presentation?.span ?? 1,
        label: metric.label,
        value: formatted.value,
        numeric: numericValue(metric),
        unit: formatted.unit,
        tone: presentation?.tone ?? ("neutral" as MetricTone),
        outOf: metric.outOf ?? null,
        max: presentation?.max ?? 100,
        // La soglia e' un parametro di sistema e arriva dal contratto: la
        // pagina sceglie come disegnare la scala, non dove cade la soglia.
        threshold: metric.threshold ?? null,
        restTone: presentation?.restTone ?? ("alert" as const),
        restNoun: presentation?.restNoun ?? "da completare",
        okLabel: presentation?.okLabel ?? "Nessun caso",
        issueLabel: presentation?.issueLabel ?? "da esaminare",
        issueTone: presentation?.issueTone ?? ("danger" as const),
        parts: metric.parts,
        context: this.contextFor(metric),
        history: metric.history
      };
    })
  );

  /**
   * Riga di contesto: risponde a "sta crescendo?" con gli ingressi di oggi. La
   * serie e' un flusso, quindi si dice "nuovi oggi" e mai "rispetto a ieri"
   * (vedi lo schema Metric).
   */
  private contextFor(metric: Metric): string | null {
    const today = newToday(metric.history);

    if (today === null || today <= 0) {
      return null;
    }

    return today === 1 ? "1 nuovo oggi" : `${today} nuovi oggi`;
  }
}

/**
 * Il valore come numero, per le schede che devono posizionarlo — la porzione
 * di una quota, il riempimento di una scala, le stelle. Il backend manda un
 * intero per i conteggi e una stringa decimale per le medie ("98.5"), quindi
 * entrambe le forme vanno riconosciute; il segnaposto "—" non e' un numero e
 * resta `null`.
 */
function numericValue(metric: Metric): number | null {
  const raw = metric.value;

  if (typeof raw === "number") {
    return raw;
  }

  const parsed = Number(raw.replace(",", "."));

  return Number.isFinite(parsed) ? parsed : null;
}
