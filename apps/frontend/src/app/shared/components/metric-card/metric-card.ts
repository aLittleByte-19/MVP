import { ChangeDetectionStrategy, Component, input } from "@angular/core";

@Component({
  selector: "mvp-metric-card",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <article class="card">
      <strong class="value">{{ value() }}</strong>
      <span class="label">{{ label() }}</span>
    </article>
  `,
  styleUrl: "./metric-card.css"
})
export class MetricCardComponent {
  readonly value = input.required<string | number>();
  readonly label = input.required<string>();
}
