export function getDocumentStatus(error?: string | null): "success" | "warning" {
  return error ? "warning" : "success";
}

export function getReviewStatusTone(
  reviewStatus?: string,
  error?: string | null
): "success" | "warning" | "danger" | "neutral" {
  if (reviewStatus === "quarantined") {
    return "danger";
  }

  if (reviewStatus === "needs_review" || error) {
    return "warning";
  }

  if (reviewStatus === "auto_validated" || reviewStatus === "manually_validated") {
    return "success";
  }

  return "neutral";
}
