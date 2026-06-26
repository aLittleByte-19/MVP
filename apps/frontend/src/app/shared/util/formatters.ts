export function formatFallback(
  value: string | number | null | undefined,
  fallback = "Non disponibile"
): string {
  if (value === null || value === undefined || value === "") {
    return fallback;
  }

  return String(value);
}

export function formatDateForDisplay(value: string | null | undefined, fallback = "Non disponibile"): string {
  if (!value) {
    return fallback;
  }

  const isoDateMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);

  if (isoDateMatch) {
    const [, year, month, day] = isoDateMatch;

    return `${day}/${month}/${year}`;
  }

  return value;
}

export function getSubDocumentNumericId(documentId: string): number {
  return Number.parseInt(documentId.replace("sub-", ""), 10);
}
