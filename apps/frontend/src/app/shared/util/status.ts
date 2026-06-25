export type StatusTone = "neutral" | "info" | "success" | "warning" | "danger";

export function getDocumentStatus(error?: string | null): "success" | "warning" {
  return error ? "warning" : "success";
}

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
