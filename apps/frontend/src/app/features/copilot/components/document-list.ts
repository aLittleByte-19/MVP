import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import type { SubDocument } from "../../../../api/generated/model";
import { ButtonComponent } from "../../../shared/components/button/button";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { formatFallback } from "../../../shared/util/formatters";
import { getReviewStatusTone } from "../../../shared/util/status";

@Component({
  selector: "poc-document-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, EmptyStateComponent, StatusBadgeComponent],
  template: `
    @if (documents().length) {
      <div class="tableWrapper">
        <table class="table">
          <thead>
            <tr>
              <th scope="col" data-column="recipient">Destinatario</th>
              <th scope="col" data-column="document">Documento</th>
              <th scope="col" data-column="file">File</th>
              <th scope="col" data-column="date">Data</th>
              <th scope="col" data-column="confidence">Conf.</th>
              <th scope="col" data-column="status">Stato</th>
              <th scope="col" data-column="actions">Azioni</th>
            </tr>
          </thead>
          <tbody>
            @for (documentItem of documents(); track documentItem.id) {
              <tr>
                <td data-column="recipient" data-label="Destinatario">
                  <span class="recipient">
                    <strong>{{ formatFallback(documentItem.employee, "Destinatario rilevato") }}</strong>
                    <small>{{ formatFallback(documentItem.company) }}</small>
                  </span>
                </td>
                <td data-column="document" data-label="Documento">
                  {{ formatFallback(documentItem.title || documentItem.type, "Documento") }}
                </td>
                <td data-column="file" data-label="File">{{ formatFallback(documentItem.file) }}</td>
                <td data-column="date" data-label="Data">
                  <span class="date">{{ formatFallback(documentItem.date) }}</span>
                </td>
                <td data-column="confidence" data-label="Conf.">
                  {{ formatFallback(documentItem.confidence, "Da verificare") }}
                </td>
                <td data-column="status" data-label="Stato">
                  <poc-status-badge [tone]="getReviewStatusTone(documentItem.reviewStatus, documentItem.error)">
                    {{ documentItem.reviewStatusLabel }}
                  </poc-status-badge>
                </td>
                <td data-column="actions" data-label="Azioni">
                  <button
                    pocButton
                    class="action"
                    [variant]="documentItem.id === selectedDocumentId() ? 'primary' : 'secondary'"
                    type="button"
                    (click)="selectDocument.emit(documentItem.id)"
                  >
                    Consulta
                  </button>
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    } @else {
      <poc-empty-state>I documenti caricati compariranno qui.</poc-empty-state>
    }
  `,
  styleUrls: ["../../../shared/components/data-table/data-table.css", "./document-list.css"]
})
export class DocumentListComponent {
  readonly documents = input<SubDocument[]>([]);
  readonly selectedDocumentId = input<string | null>(null);
  readonly selectDocument = output<string>();

  protected readonly formatFallback = formatFallback;
  protected readonly getReviewStatusTone = getReviewStatusTone;
}
