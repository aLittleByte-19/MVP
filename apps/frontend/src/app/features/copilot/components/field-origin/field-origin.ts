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
 * Indicatore della provenienza del dato, dentro la casella e a sinistra.
 *
 * Stava accanto all'etichetta come glifo tipografico, ripetuto su tredici
 * campi e decifrabile solo tornando a una legenda in cima al pannello. Dentro
 * la casella diventa parte del campo: un separatore verticale lo stacca dal
 * valore, e il colore che porta e' quello dello stato — arancione sotto
 * soglia, teal sopra, verde dopo la conferma umana.
 *
 * Il nome accessibile e' dentro un `srOnly`: il componente vive dentro la
 * `<label>` del campo, quindi lo screen reader legge "Nome e cognome, estratto
 * dall'AI" come una cosa sola. Il `title` da' la stessa informazione col
 * passaggio del mouse, per chi vede l'icona e non la riconosce.
 *
 * L'origine e' quella del singolo campo: il contratto porta la confidenza di
 * ciascun campo e l'elenco di quelli sotto la propria soglia (ADR 0013), quindi
 * dentro lo stesso documento una casella puo' essere teal e quella accanto
 * arancione. Chiude la questione aperta 2 dell'ADR 0012.
 *
 * Quando la confidenza e' nota il suggerimento la riporta: dire "da verificare"
 * senza dire quanto e' un giudizio senza la sua misura.
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
 * Campi estratti a cui corrisponde un controllo del pannello, per chiave del
 * contratto. Il nominativo ne raccoglie due, perche' la casella e' una sola.
 *
 * Tipologia e descrizione non compaiono: il modello le compone invece di
 * copiarle dal foglio, quindi non hanno una riga OCR da cui farle venire e
 * nemmeno una confidenza propria (ADR 0013).
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
 * Provenienza del singolo campo, non piu' dell'intero sotto-documento.
 *
 * Chiude la questione aperta 2 dell'ADR 0012: il contratto ora porta la
 * confidenza di ogni campo e l'elenco di quelli sotto la propria soglia, quindi
 * dentro un documento in revisione si vede *quale* dato e' dubbio invece di
 * vedere tredici caselle tutte uguali.
 *
 * La conferma manuale vale per tutto il sotto-documento e prevale: quando
 * l'operatore ha validato, i campi portano il suo segno, non piu' quello
 * dell'AI. Un documento elaborato prima del dettaglio per riga non ha nulla da
 * dire sui singoli campi e ricade sullo stato complessivo, che e' il
 * comportamento precedente.
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
