import { ChangeDetectionStrategy, Component } from "@angular/core";

@Component({
  selector: "mvp-empty-state",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<p class="empty"><ng-content /></p>`,
  styleUrl: "./empty-state.css"
})
export class EmptyStateComponent {}
