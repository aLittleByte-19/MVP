import { CheckCircle2, Pencil, Save, Trash2, X } from "lucide-react";
import { useEffect, useState } from "react";
import type { SubDocument, UpdateExtractedDataRequest } from "../../../api/generated/model";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Button } from "../../../components/inputs/Button";
import { Section } from "../../../components/layout/Section";
import { formatFallback } from "../../../lib/formatters";
import { DocumentStatusTimeline } from "./DocumentStatusTimeline";
import styles from "./SubDocumentList.module.css";

type SubDocumentListProps = {
  documentItem: SubDocument | null;
  isDeleting: boolean;
  isSavingReview: boolean;
  onDelete: (documentId: string) => void;
  onMarkReviewed: (documentId: string) => void;
  onSaveReview: (payload: { documentId: string; payload: UpdateExtractedDataRequest }) => void;
  reviewError?: string | null;
};

type ReviewFormState = {
  employeeName: string;
  employeeFirstName: string;
  employeeLastName: string;
  companyName: string;
  documentDate: string;
  documentType: string;
  description: string;
};

const emptyReviewForm: ReviewFormState = {
  employeeName: "",
  employeeFirstName: "",
  employeeLastName: "",
  companyName: "",
  documentDate: "",
  documentType: "",
  description: ""
};

function compactEmployeeName(documentItem: SubDocument): string {
  const fromAtomicFields = [documentItem.employeeFirstName, documentItem.employeeLastName]
    .filter(Boolean)
    .join(" ")
    .trim();

  return documentItem.employee ?? fromAtomicFields;
}

function toReviewForm(documentItem: SubDocument | null): ReviewFormState {
  if (!documentItem) {
    return emptyReviewForm;
  }

  return {
    employeeName: compactEmployeeName(documentItem),
    employeeFirstName: documentItem.employeeFirstName ?? "",
    employeeLastName: documentItem.employeeLastName ?? "",
    companyName: documentItem.companyName ?? documentItem.company ?? "",
    documentDate: documentItem.documentDate ?? "",
    documentType: documentItem.documentType ?? documentItem.type ?? "",
    description: documentItem.description ?? ""
  };
}

function nullableTrim(value: string): string | null {
  const trimmed = value.trim();

  return trimmed === "" ? null : trimmed;
}

function splitEmployeeName(formState: ReviewFormState): Pick<
  UpdateExtractedDataRequest,
  "employeeFirstName" | "employeeLastName"
> {
  const employeeName = formState.employeeName.trim().replace(/\s+/g, " ");
  const previousName = [formState.employeeFirstName, formState.employeeLastName]
    .filter(Boolean)
    .join(" ")
    .trim()
    .replace(/\s+/g, " ");

  if (employeeName === "") {
    return { employeeFirstName: null, employeeLastName: null };
  }

  if (employeeName === previousName) {
    return {
      employeeFirstName: nullableTrim(formState.employeeFirstName),
      employeeLastName: nullableTrim(formState.employeeLastName)
    };
  }

  const parts = employeeName.split(" ");

  if (parts.length === 1) {
    return { employeeFirstName: parts[0], employeeLastName: null };
  }

  return {
    employeeFirstName: parts.slice(0, -1).join(" "),
    employeeLastName: parts[parts.length - 1] ?? null
  };
}

export function SubDocumentList({
  documentItem,
  isDeleting,
  isSavingReview,
  onDelete,
  onMarkReviewed,
  onSaveReview,
  reviewError
}: SubDocumentListProps) {
  const [formState, setFormState] = useState<ReviewFormState>(() => toReviewForm(documentItem));
  const [isEditing, setIsEditing] = useState(false);

  useEffect(() => {
    setFormState(toReviewForm(documentItem));
    setIsEditing(false);
  }, [documentItem]);

  if (!documentItem) {
    return (
      <Section title="Verifica documento">
        <EmptyState>Seleziona un documento dallo storico per visualizzare il dettaglio.</EmptyState>
      </Section>
    );
  }

  const setField = (field: keyof ReviewFormState, value: string) => {
    setFormState((current) => ({ ...current, [field]: value }));
  };
  const buildPayload = (markAsValidated: boolean): UpdateExtractedDataRequest => {
    const employee = splitEmployeeName(formState);

    return {
      ...employee,
      companyName: nullableTrim(formState.companyName),
      documentDate: formState.documentDate || null,
      documentType: nullableTrim(formState.documentType),
      description: nullableTrim(formState.description),
      markAsValidated
    };
  };
  const saveReview = (markAsValidated: boolean) => {
    onSaveReview({ documentId: documentItem.id, payload: buildPayload(markAsValidated) });
  };
  const confidenceDisplay = documentItem.confidence != null ? `${documentItem.confidence}%` : "Da verificare";
  const inspectorClassName = [
    styles.inspectorForm,
    isEditing ? styles.isEditing : null,
    documentItem.confidence != null && documentItem.confidence < 80 ? styles.confidenceLow : null
  ].filter(Boolean).join(" ");

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
            {documentItem.previewUrl ? (
              <>
                <iframe
                  className={styles.previewDocument}
                  src={documentItem.previewUrl}
                  title={`Anteprima di ${formatFallback(documentItem.file, "documento")}`}
                />
                <a className={styles.previewLink} href={documentItem.previewUrl} target="_blank" rel="noreferrer">
                  Apri in una nuova scheda
                </a>
              </>
            ) : (
              <span>Anteprima non ancora disponibile per questo documento.</span>
            )}
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
          <div className={styles.inspectorHeading}>
            <p className={styles.eyebrow}>Dati estratti dall'OCR</p>
            <div className={styles.fieldLegend} aria-label="Legenda campi">
              <span><i className={styles.editableDot} />Modificabile</span>
              <span><i className={styles.lockedDot} />Sola lettura</span>
            </div>
          </div>
          {documentItem.error ? <p className={styles.errorNote}>{documentItem.error}</p> : null}
          {reviewError ? <p className={styles.errorNote}>{reviewError}</p> : null}

          <form className={inspectorClassName} onSubmit={(event) => {
            event.preventDefault();
            saveReview(false);
          }}>
            <div className={styles.inspectorGrid}>
              <label className={`${styles.field} ${styles.editableField}`}>
                <span>Nome e cognome</span>
                <input
                  value={formState.employeeName}
                  readOnly={!isEditing}
                  aria-readonly={!isEditing}
                  onChange={(event) => setField("employeeName", event.target.value)}
                />
              </label>
              <label className={`${styles.field} ${styles.editableField}`}>
                <span>Azienda</span>
                <input
                  value={formState.companyName}
                  readOnly={!isEditing}
                  aria-readonly={!isEditing}
                  onChange={(event) => setField("companyName", event.target.value)}
                />
              </label>
              <label className={`${styles.field} ${styles.lockedField}`}>
                <span>Nome file</span>
                <input value={formatFallback(documentItem.file)} readOnly disabled tabIndex={-1} />
              </label>
              <label className={`${styles.field} ${styles.editableField}`}>
                <span>Data documento</span>
                <input
                  type="date"
                  value={formState.documentDate}
                  readOnly={!isEditing}
                  aria-readonly={!isEditing}
                  onChange={(event) => setField("documentDate", event.target.value)}
                />
              </label>
              <label className={`${styles.field} ${styles.lockedField}`}>
                <span>Numero pagine</span>
                <input value={formatFallback(documentItem.pages)} readOnly disabled tabIndex={-1} />
              </label>
              <label className={`${styles.field} ${styles.editableField}`}>
                <span>Tipologia documento</span>
                <input
                  value={formState.documentType}
                  readOnly={!isEditing}
                  aria-readonly={!isEditing}
                  onChange={(event) => setField("documentType", event.target.value)}
                />
              </label>
              <label className={`${styles.field} ${styles.lockedField}`}>
                <span>Confidenza</span>
                <input value={confidenceDisplay} readOnly disabled tabIndex={-1} />
              </label>
              <label className={`${styles.field} ${styles.lockedField}`}>
                <span>Stato revisione</span>
                <input value={documentItem.reviewStatusLabel} readOnly disabled tabIndex={-1} />
              </label>
              <label className={`${styles.field} ${styles.editableField} ${styles.formFull}`}>
                <span>Descrizione</span>
                <textarea
                  rows={3}
                  value={formState.description}
                  readOnly={!isEditing}
                  aria-readonly={!isEditing}
                  onChange={(event) => setField("description", event.target.value)}
                />
              </label>
            </div>

            <div className={styles.reviewActions}>
              {isEditing ? (
                <>
                  <Button variant="secondary" disabled={isSavingReview} onClick={() => {
                    setFormState(toReviewForm(documentItem));
                    setIsEditing(false);
                  }}>
                    <X aria-hidden="true" size={16} />
                    Annulla
                  </Button>
                  <Button variant="secondary" type="submit" disabled={isSavingReview}>
                    <Save aria-hidden="true" size={16} />
                    Salva correzioni
                  </Button>
                  <Button disabled={isSavingReview} onClick={() => saveReview(true)}>
                    <CheckCircle2 aria-hidden="true" size={16} />
                    Salva e valida
                  </Button>
                </>
              ) : (
                <>
                  <Button
                    variant="secondary"
                    disabled={isSavingReview}
                    onClick={() => onMarkReviewed(documentItem.id)}
                  >
                    <CheckCircle2 aria-hidden="true" size={16} />
                    Conferma dati correnti
                  </Button>
                  <Button variant="secondary" disabled={isSavingReview} onClick={() => setIsEditing(true)}>
                    <Pencil aria-hidden="true" size={16} />
                    Modifica
                  </Button>
                </>
              )}
            </div>
          </form>
        </article>
      </div>
    </Section>
  );
}
