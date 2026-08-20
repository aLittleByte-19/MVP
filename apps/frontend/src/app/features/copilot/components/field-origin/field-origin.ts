import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { LucideCircleHelp, LucideLock, LucidePenLine, LucideSparkles } from "@lucide/angular";

/** Da dove viene il dato che sta nel campo. */
export type FieldOrigin = "auto" | "manual" | "review" | "locked";

const LABELS: Record<FieldOrigin, string> = {
  auto: "Estratto dall'AI e validato in automatico",
  manual: "Confermato dall'operatore",
  review: "Estratto dall'AI, da verificare",
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
 * Nota: l'origine e' quella del sotto-documento, non del singolo campo. Il
 * contratto non porta una provenienza per campo (questione aperta 2 dell'ADR
 * 0012) e finche' non ci sara' tutti i campi estratti mostrano lo stesso
 * segno.
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

  protected readonly label = computed(() => LABELS[this.origin()]);
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
