import { ChangeDetectionStrategy, Component, input } from "@angular/core";
import type { StatusTone } from "../../util/status";

@Component({
  selector: "mvp-status-badge",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<span class="badge" [class.info]="tone() === 'info'" [class.success]="tone() === 'success'" [class.warning]="tone() === 'warning'" [class.danger]="tone() === 'danger'"><ng-content /></span>`,
  styleUrl: "./status-badge.css"
})
export class StatusBadgeComponent {
  readonly tone = input<StatusTone>("neutral");
}
