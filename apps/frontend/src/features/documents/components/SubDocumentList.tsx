import { Trash2 } from "lucide-react";
import type { SubDocument } from "../../../api/generated/model";
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

type DetailField = { label: string; value: string; full?: boolean };

export function SubDocumentList({ documentItem, isDeleting, onDelete }: SubDocumentListProps) {
  if (!documentItem) {
    return (
      <Section title="Verifica documento">
        <EmptyState>Seleziona un documento dallo storico per visualizzare il dettaglio.</EmptyState>
      </Section>
    );
  }

  const fields: DetailField[] = [
    { label: "Nome e cognome", value: formatFallback(documentItem.employee) },
    { label: "Azienda", value: formatFallback(documentItem.company) },
    { label: "Nome file", value: formatFallback(documentItem.file) },
    { label: "Data documento", value: formatFallback(documentItem.date) },
    { label: "Numero pagine", value: formatFallback(documentItem.pages) },
    { label: "Tipologia documento", value: formatFallback(documentItem.type) },
    {
      label: "Confidenza",
      value: documentItem.confidence != null ? `${documentItem.confidence}%` : "Da verificare"
    },
    { label: "Descrizione", value: formatFallback(documentItem.description), full: true }
  ];

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

      <div className={styles.detailGrid}>
        <article className={styles.preview}>
          <p className={styles.eyebrow}>Anteprima documento</p>
          <div className={styles.previewFrame}>
            <strong>{formatFallback(documentItem.file, "Documento")}</strong>
            <span>Apertura del documento originale direttamente nell'applicativo.</span>
            {documentItem.previewUrl ? (
              <a className={styles.previewLink} href={documentItem.previewUrl} target="_blank" rel="noreferrer">
                Apri originale
              </a>
            ) : null}
          </div>
          {documentItem.previewLines.length ? (
            <ul className={styles.previewLines}>
              {documentItem.previewLines.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          ) : null}
        </article>

        <article className={styles.extracted}>
          <p className={styles.eyebrow}>Dati estratti dall'OCR</p>
          {documentItem.error ? <p className={styles.errorNote}>{documentItem.error}</p> : null}
          <dl className={styles.fieldGrid}>
            {fields.map((field) => (
              <div key={field.label} className={field.full ? styles.fieldFull : undefined}>
                <dt>{field.label}</dt>
                <dd>{field.value}</dd>
              </div>
            ))}
          </dl>
        </article>
      </div>
    </Section>
  );
}
