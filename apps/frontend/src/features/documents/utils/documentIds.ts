import { getSubDocumentNumericId } from "../../../lib/formatters";

export function getDocumentDeleteId(documentId: string): number {
  return getSubDocumentNumericId(documentId);
}
