import { ChangeDetectionStrategy, Component } from "@angular/core";

@Component({
  selector: "poc-empty-state",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<p class="empty"><ng-content /></p>`,
  styleUrl: "./empty-state.css"
})
export class EmptyStateComponent {}
