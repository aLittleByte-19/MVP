export function formatFallback(value: string | number | null | undefined, fallback = "Non disponibile"): string {
  if (value === null || value === undefined || value === "") {
    return fallback;
  }

  return String(value);
}

export function getSubDocumentNumericId(documentId: string): number {
  return Number.parseInt(documentId.replace("sub-", ""), 10);
}
