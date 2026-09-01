import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { LucideCircleHelp, LucideLock, LucidePenLine, LucideSparkles } from "@lucide/angular";

/** Da dove viene il dato che sta nel campo. */
export type FieldOrigin = "auto" | "manual" | "review" | "locked";

const LABELS: Record<FieldOrigin, string> = {
  auto: "Estratto dall'AI con buona confidenza",
  manual: "Corretto a mano dall'operatore",
  review: "Estratto dall'AI con confidenza sotto soglia, da revisionare",
  locked: "Dato di sistema, non modificabile"
};

/**
 * Indicatore della provenienza del dato, dentro la casella. Il nome accessibile e'
 * in `srOnly` (il componente vive dentro la `<label>` del campo, quindi lo screen
 * reader legge "Nome e cognome, estratto dall'AI" come una cosa sola).
 */
@Component({
  selector: "mvp-field-origin",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [LucideCircleHelp, LucideLock, LucidePenLine, LucideSparkles],
  template: `
    @switch (origin()) {
      @case ("manual") {
        <svg lucidePenLine aria-hidden="true"></svg>
      }
      @case ("review") {
        <svg lucideCircleHelp aria-hidden="true"></svg>
      }
      @case ("locked") {
        <svg lucideLock aria-hidden="true"></svg>
      }
      @default {
        <svg lucideSparkles aria-hidden="true"></svg>
      }
    }
    <span class="srOnly">{{ label() }}</span>
  `,
  styleUrl: "./field-origin.css",
  host: {
    "[class]": "origin()",
    "[attr.title]": "label()"
  }
})
export class FieldOriginComponent {
  readonly origin = input.required<FieldOrigin>();

  /** Leggibilita' del testo da cui viene il campo, quando e' nota. */
  readonly confidence = input<number | null>(null);

  protected readonly label = computed(() => {
    const label = LABELS[this.origin()];
    const confidence = this.confidence();

    if (confidence === null || this.origin() === "manual" || this.origin() === "locked") {
      return label;
    }

    return `${label} (letto al ${Math.round(confidence)}%)`;
  });
}

/** Traduce lo stato di revisione del sotto-documento nella provenienza dei suoi campi. */
export function originForReviewStatus(reviewStatus: string | undefined): FieldOrigin {
  switch (reviewStatus) {
    case "manually_validated":
      return "manual";
    case "auto_validated":
      return "auto";
    default:
      return "review";
  }
}

/**
 * Campi estratti a cui corrisponde un controllo del pannello, per chiave del contratto.
 * Tipologia e descrizione non compaiono: il modello le compone, senza confidenza propria (ADR 0013).
 */
export const EXTRACTED_FIELD_KEYS: Record<string, readonly string[] | undefined> = {
  employeeName: ["employee_first_name", "employee_last_name"],
  employeeFirstName: ["employee_first_name"],
  employeeLastName: ["employee_last_name"],
  companyName: ["company_name"],
  documentDate: ["document_date"],
  recipientEmail: ["recipient_email"],
  fiscalCode: ["fiscal_code"],
  employeeId: ["employee_id"]
};

interface DocumentOriginSource {
  readonly reviewStatus?: string;
  readonly fieldConfidences?: Record<string, number | null> | null;
  readonly lowConfidenceFields?: readonly string[];
}

/**
 * Provenienza del singolo campo (ADR 0012, questione 2), non piu' dell'intero
 * sotto-documento. La conferma manuale prevale su tutto il sotto-documento; senza
 * confidenze per campo ricade sullo stato complessivo.
 */
export function originForField(
  documentItem: DocumentOriginSource,
  keys: readonly string[] | undefined
): FieldOrigin {
  if (documentItem.reviewStatus === "manually_validated") {
    return "manual";
  }

  if (!keys || !documentItem.fieldConfidences) {
    return originForReviewStatus(documentItem.reviewStatus);
  }

  const low = documentItem.lowConfidenceFields ?? [];

  return keys.some((key) => low.includes(key)) ? "review" : "auto";
}
