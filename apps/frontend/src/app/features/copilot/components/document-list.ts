import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import type { SubDocument } from "../../../../api/generated/model";
import { ButtonComponent } from "../../../shared/components/button/button";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { StatusDotComponent } from "../../../shared/components/status-dot/status-dot";
import { formatConfidence, formatDateForDisplay, formatFallback } from "../../../shared/util/formatters";
import { getReviewStatusTone } from "../../../shared/util/status";

/**
 * Storico documenti analizzati.
 *
 * Le colonne portano cio' che riguarda l'analisi — tipologia, quando e' stato
 * caricato, quanto e' affidabile l'estrazione, a che punto e' la revisione, se
 * e' stato scaricato — mentre il documento di partenza (destinatario, azienda,
 * nome del file, data del documento) sta tutto nella prima cella. Erano nove
 * colonne alla pari, e la confidenza ne aveva il 6%: la riga si leggeva come
 * un elenco di campi, non come un documento in lavorazione.
 */
@Component({
  selector: "mvp-document-list",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, EmptyStateComponent, StatusBadgeComponent, StatusDotComponent],
  template: `
    @if (documents().length) {
      <div class="tableWrapper">
        <table class="table">
          <thead>
            <tr>
              <th scope="col" data-column="recipient">Destinatario</th>
              <th scope="col" data-column="type">Tipologia</th>
              <th scope="col" data-column="uploadedAt">Caricato il</th>
              <th scope="col" data-column="confidence">Confidenza</th>
              <th scope="col" data-column="review">Validazione</th>
              <th scope="col" data-column="download">Scaricamento</th>
              <th scope="col" data-column="actions">Azioni</th>
            </tr>
          </thead>
          <tbody>
            @for (documentItem of documents(); track documentItem.id) {
              <tr>
                <td data-column="recipient" data-label="Destinatario">
                  <span class="recipient">
                    <strong>{{ formatFallback(documentItem.employee, "Destinatario rilevato") }}</strong>
                    <small>{{ formatFallback(documentItem.companyName) }}</small>
                    <small class="origin">
                      {{ formatFallback(documentItem.file) }} ·
                      {{ formatDateForDisplay(documentItem.documentDate) }}
                    </small>
                  </span>
                </td>
                <td data-column="type" data-label="Tipologia">
                  {{ formatFallback(documentItem.title || documentItem.documentType, "Documento") }}
                </td>
                <td data-column="uploadedAt" data-label="Caricato il">
                  <span class="stamp">{{ formatFallback(documentItem.uploadedAt) }}</span>
                </td>
                <td data-column="confidence" data-label="Confidenza">
                  <span class="figure">{{ formatConfidence(documentItem.confidence) }}</span>
                </td>
                <td data-column="review" data-label="Validazione">
                  <mvp-status-badge [tone]="getReviewStatusTone(documentItem.reviewStatus, documentItem.error)">
                    {{ documentItem.reviewStatusLabel }}
                  </mvp-status-badge>
                </td>
                <td data-column="download" data-label="Scaricamento">
                  <mvp-status-dot [done]="documentItem.sendStatus === 'sent'">
                    {{ documentItem.sendStatusLabel }}
                  </mvp-status-dot>
                </td>
                <td data-column="actions" data-label="Azioni">
                  <button
                    mvpButton
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
      <mvp-empty-state>{{ emptyMessage() }}</mvp-empty-state>
    }
  `,
  styleUrls: ["../../../shared/components/data-table/data-table.css", "./document-list.css"]
})
export class DocumentListComponent {
  readonly documents = input<SubDocument[]>([]);
  readonly selectedDocumentId = input<string | null>(null);
  readonly emptyMessage = input<string>("I documenti caricati compariranno qui.");
  readonly selectDocument = output<string>();

  protected readonly formatFallback = formatFallback;
  protected readonly formatConfidence = formatConfidence;
  protected readonly formatDateForDisplay = formatDateForDisplay;
  protected readonly getReviewStatusTone = getReviewStatusTone;
}
