import { FormControl, FormGroup } from "@angular/forms";
import type { SubDocumentSendStatus } from "../../../../api/generated/model";
import type { ConfidenceCriterion, DocumentFilters } from "./document-workflow.service";

/**
 * Form dei filtri dello storico documenti (UC-35..UC-38).
 *
 * I due campi legati a un `input[type=number]` sono tipizzati `number | null`
 * perche' e' quello che ci scrive dentro Angular tramite NumberValueAccessor:
 * tiparli come stringa fa fallire a runtime qualunque operazione testuale sul
 * valore, e l'eccezione, sollevata dentro il next handler di `valueChanges`,
 * chiude la sottoscrizione e disattiva in silenzio l'intera barra dei filtri.
 */
export function createDocumentFilterForm() {
  return new FormGroup({
    search: new FormControl("", { nonNullable: true }),
    sendStatus: new FormControl("", { nonNullable: true }),
    confidenceCriterion: new FormControl("below", { nonNullable: true }),
    confidenceThreshold: new FormControl<number | null>(null),
    month: new FormControl("", { nonNullable: true }),
    year: new FormControl<number | null>(null)
  });
}

export type DocumentFilterFormValue = Partial<ReturnType<typeof createDocumentFilterForm>["value"]>;

/**
 * Converte i valori del form nei criteri inviati all'API, scartando quelli
 * vuoti. Totale per costruzione: nessun ramo puo' sollevare, cosi' un valore
 * inatteso non puo' piu' spegnere lo stream dei filtri.
 */
export function toDocumentFilters(value: DocumentFilterFormValue): DocumentFilters {
  const filters: DocumentFilters = {};

  if (value.search?.trim()) {
    filters.search = value.search.trim();
  }

  if (value.sendStatus) {
    filters.sendStatus = value.sendStatus as SubDocumentSendStatus;
  }

  const threshold = value.confidenceThreshold;
  if (typeof threshold === "number" && Number.isFinite(threshold)) {
    filters.confidenceThreshold = threshold;
    filters.confidenceCriterion = (value.confidenceCriterion || "below") as ConfidenceCriterion;
  }

  if (value.month) {
    filters.month = Number(value.month);
  }

  const year = value.year;
  if (typeof year === "number" && Number.isFinite(year)) {
    filters.year = year;
  }

  return filters;
}
