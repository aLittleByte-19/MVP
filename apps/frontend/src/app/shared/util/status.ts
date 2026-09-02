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

/** Forma breve per una colonna stretta di tabella: la frase completa del backend andrebbe a capo. La forma estesa resta nell'ispettore. */
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
