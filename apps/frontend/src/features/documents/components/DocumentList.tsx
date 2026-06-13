import type { SubDocument } from "../../../api/generated/model";
import { DataTable } from "../../../components/data-display/DataTable";
import { StatusBadge } from "../../../components/data-display/StatusBadge";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Button } from "../../../components/inputs/Button";
import { formatFallback } from "../../../lib/formatters";
import { getReviewStatusTone } from "../../../lib/status";
import styles from "./DocumentList.module.css";

type DocumentListProps = {
  documents: SubDocument[];
  onSelect: (documentId: string) => void;
  selectedDocumentId?: string | null;
};

export function DocumentList({ documents, onSelect, selectedDocumentId }: DocumentListProps) {
  if (!documents.length) {
    return <EmptyState>I documenti caricati compariranno qui.</EmptyState>;
  }

  return (
    <DataTable
      rows={documents}
      getRowKey={(documentItem) => documentItem.id}
      columns={[
        {
          key: "recipient",
          header: "Destinatario",
          render: (documentItem) => (
            <span className={styles.recipient}>
              <strong>{formatFallback(documentItem.employee, "Destinatario rilevato")}</strong>
              <small>{formatFallback(documentItem.company)}</small>
            </span>
          )
        },
        {
          key: "document",
          header: "Documento",
          render: (documentItem) => formatFallback(documentItem.title || documentItem.type, "Documento")
        },
        { key: "file", header: "File", render: (documentItem) => formatFallback(documentItem.file) },
        { key: "date", header: "Data", render: (documentItem) => formatFallback(documentItem.date) },
        {
          key: "confidence",
          header: "Conf.",
          render: (documentItem) => formatFallback(documentItem.confidence, "Da verificare")
        },
        {
          key: "status",
          header: "Stato",
          render: (documentItem) => {
            const status = getReviewStatusTone(documentItem.reviewStatus, documentItem.error);

            return (
              <StatusBadge tone={status}>
                {documentItem.reviewStatusLabel}
              </StatusBadge>
            );
          }
        },
        {
          key: "actions",
          header: "Azioni",
          render: (documentItem) => {
            const isActive = documentItem.id === selectedDocumentId;

            return (
              <Button
                className={styles.action}
                variant={isActive ? "primary" : "secondary"}
                onClick={() => onSelect(documentItem.id)}
              >
                Consulta
              </Button>
            );
          }
        }
      ]}
    />
  );
}
