import { ChangeDetectionStrategy, Component, input } from "@angular/core";
import type { Communication } from "../../../../api/generated/model";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StarRating } from "../../../shared/components/star-rating/star-rating";
import { formatFallback } from "../../../shared/util/formatters";

/**
 * UC-27.3: gli ultimi contenuti valutati, con voto e commento testuale — non
 * solo la media (UC-27.2). Sola lettura: nessuna selezione, eliminazione o
 * preferito, a differenza di {@see CommunicationHistoryListComponent}.
 */
@Component({
  selector: "mvp-recent-feedback-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EmptyStateComponent, StarRating],
  template: `
    @if (feedback().length) {
      @for (communication of feedback(); track communication.id) {
        <div class="card">
          <div class="cardHeader">
            <span class="title">{{ communication.title }}</span>
            <mvp-star-rating
              [rating]="communication.rating ?? 0"
              [disabled]="true"
              [label]="'Voto per ' + formatFallback(communication.title, 'contenuto')"
            ></mvp-star-rating>
          </div>
          <p class="date">{{ formatFallback(communication.ratedAt) }}</p>
          @if (communication.ratingComment) {
            <p class="comment">{{ communication.ratingComment }}</p>
          }
        </div>
      }
    } @else {
      <mvp-empty-state>Nessun feedback ricevuto finora.</mvp-empty-state>
    }
  `,
  styleUrls: ["../../../shared/styles/notice.css", "./recent-feedback-list.css"]
})
export class RecentFeedbackListComponent {
  readonly feedback = input<Communication[]>([]);

  protected readonly formatFallback = formatFallback;
}
