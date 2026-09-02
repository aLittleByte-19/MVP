import { FormControl, FormGroup } from "@angular/forms";
import type { SubDocumentSendStatus } from "../../../../api/generated/model";
import type { ConfidenceCriterion, DocumentFilters } from "./document-workflow.service";

/**
 * Form dei filtri dello storico documenti (UC-35..UC-38). I campi `number` sono
 * tipizzati `number | null` perche' e' quello che NumberValueAccessor ci scrive.
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

/** Converte i valori del form nei criteri API, scartando quelli vuoti. Nessun ramo solleva. */
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
