import { useEffect, useMemo, useState } from "react";
import type { SubDocument } from "../../../api/generated/model";

export function useDocuments(documents: SubDocument[]) {
  const [selectedDocumentId, setSelectedDocumentId] = useState<string | null>(null);

  const selectedDocument = useMemo(() => {
    return documents.find((documentItem) => documentItem.id === selectedDocumentId) ?? documents[0] ?? null;
  }, [documents, selectedDocumentId]);

  useEffect(() => {
    if (selectedDocument?.id) {
      setSelectedDocumentId(selectedDocument.id);
    }
  }, [selectedDocument?.id]);

  return {
    selectedDocument,
    selectedDocumentId,
    selectDocument: setSelectedDocumentId
  };
}
