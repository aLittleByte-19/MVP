import { Trash2 } from "lucide-react";
import type { SubDocument } from "../../../api/generated/model";
import { DescriptionList } from "../../../components/data-display/DescriptionList";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Button } from "../../../components/inputs/Button";
import { Section } from "../../../components/layout/Section";
import { formatFallback } from "../../../lib/formatters";
import { DocumentStatusTimeline } from "./DocumentStatusTimeline";
import styles from "./SubDocumentList.module.css";

type SubDocumentListProps = {
  documentItem: SubDocument | null;
  isDeleting: boolean;
  onDelete: (documentId: string) => void;
};

export function SubDocumentList({ documentItem, isDeleting, onDelete }: SubDocumentListProps) {
  if (!documentItem) {
    return (
      <Section title="Verifica documento">
        <EmptyState>Seleziona un documento dallo storico per visualizzare il dettaglio.</EmptyState>
      </Section>
    );
  }

  return (
    <Section
      title={documentItem.title || "Verifica documento"}
      actions={
        <Button
          variant="icon"
          aria-label="Elimina documento"
          disabled={isDeleting}
          onClick={() => onDelete(documentItem.id)}
        >
          <Trash2 aria-hidden="true" />
        </Button>
      }
    >
      <DocumentStatusTimeline documentItem={documentItem} />
      <DescriptionList
        items={[
          { label: "Dipendente", value: formatFallback(documentItem.employee) },
          { label: "Azienda", value: formatFallback(documentItem.company) },
          { label: "Data", value: formatFallback(documentItem.date) },
          { label: "Confidenza", value: formatFallback(documentItem.confidence, "Da verificare") }
        ]}
      />
      {documentItem.previewUrl ? (
        <iframe className={styles.previewFrame} title="Anteprima documento" src={documentItem.previewUrl} />
      ) : null}
      {documentItem.previewLines.length ? (
        <ul className={styles.previewLines}>
          {documentItem.previewLines.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      ) : null}
    </Section>
  );
}
