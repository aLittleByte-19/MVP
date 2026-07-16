import { ChangeDetectionStrategy, Component, input } from "@angular/core";
import type { SubDocument } from "../../../../api/generated/model";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { getReviewStatusTone } from "../../../shared/util/status";

@Component({
  selector: "mvp-document-status-timeline",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [StatusBadgeComponent],
  template: `
    <ul class="timeline" aria-label="Stato documento">
      <li>
        <mvp-status-badge [tone]="getReviewStatusTone(documentItem().reviewStatus, documentItem().error)">
          {{ documentItem().reviewStatusLabel }}
        </mvp-status-badge>
      </li>
    </ul>
  `,
  styleUrl: "./document-status-timeline.css"
})
export class DocumentStatusTimelineComponent {
  readonly documentItem = input.required<SubDocument>();
  protected readonly getReviewStatusTone = getReviewStatusTone;
}
