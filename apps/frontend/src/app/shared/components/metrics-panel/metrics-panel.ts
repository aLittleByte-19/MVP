import { ChangeDetectionStrategy, Component, input } from "@angular/core";
import type { Metric } from "../../../../api/generated/model";
import { EmptyStateComponent } from "../empty-state/empty-state";
import { LoadingStateComponent } from "../loading-state/loading-state";
import { MetricCardComponent } from "../metric-card/metric-card";

@Component({
  selector: "poc-metrics-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EmptyStateComponent, LoadingStateComponent, MetricCardComponent],
  template: `
    @if (isLoading()) {
      <poc-loading-state label="Caricamento metriche." />
    } @else if (metrics().length) {
      <div class="grid">
        @for (metric of metrics(); track metric.label) {
          <poc-metric-card [label]="metric.label" [value]="metric.value" />
        }
      </div>
    } @else {
      <poc-empty-state>Nessuna metrica disponibile.</poc-empty-state>
    }
  `,
  styleUrl: "./metrics-panel.css"
})
export class MetricsPanelComponent {
  readonly isLoading = input<boolean>(false);
  readonly metrics = input<Metric[]>([]);
}
