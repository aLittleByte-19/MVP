import { ChangeDetectionStrategy, Component, input } from "@angular/core";

@Component({
  selector: "mvp-alert",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="alert" role="alert" aria-live="polite">
      <h2>{{ title() }}</h2>
      <p><ng-content /></p>
    </section>
  `,
  styleUrl: "./alert.css"
})
export class AlertComponent {
  readonly title = input.required<string>();
}
