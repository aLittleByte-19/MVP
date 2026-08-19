export type StatusTone = "neutral" | "info" | "success" | "warning" | "danger";

export function getReviewStatusTone(reviewStatus?: string, error?: string | null): StatusTone {
  if (reviewStatus === "quarantined") {
    return "danger";
  }

  if (reviewStatus === "needs_review" || error) {
    return "warning";
  }

  // Validato manualmente (o campi corretti/confermati a mano) -> verde.
  if (reviewStatus === "manually_validated") {
    return "success";
  }

  // Validato automaticamente con alta confidenza -> azzurro, per distinguerlo
  // a colpo d'occhio dalla validazione manuale.
  if (reviewStatus === "auto_validated") {
    return "info";
  }

  return "neutral";
}

/**
 * Etichetta dello stato di revisione nella forma breve, per la colonna di una
 * tabella. Quella del backend e' una frase — "Validato automaticamente" — che
 * in una colonna larga un ottavo di schermo va a capo in mezzo alla parola;
 * sotto l'intestazione "Validazione" la sola qualificazione dice gia' tutto.
 * La forma estesa resta dov'e' leggibile per intero, nell'ispettore.
 */
export function getReviewStatusShortLabel(reviewStatus: string | undefined, fallback: string): string {
  switch (reviewStatus) {
    case "auto_validated":
      return "Automatica";
    case "manually_validated":
      return "Manuale";
    case "needs_review":
      return "Da verificare";
    case "quarantined":
      return "Quarantena";
    default:
      return fallback;
  }
}
