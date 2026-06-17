import { FileDropzone } from "../../../components/inputs/FileDropzone";
import { Section } from "../../../components/layout/Section";
import styles from "./DocumentUploadPanel.module.css";

type DocumentUploadPanelProps = {
  isUploading: boolean;
  onUpload: (file: File) => void;
  status: string;
};

export function DocumentUploadPanel({ isUploading, onUpload, status }: DocumentUploadPanelProps) {
  return (
    <Section id="copilot-upload" title="Carica nel repository">
      <p className={styles.note}>
        Il sistema analizza automaticamente il documento dopo il caricamento e restituisce i campi rilevati
        nella verifica.
      </p>
      <FileDropzone disabled={isUploading} label="Seleziona o trascina un documento" onFileSelected={onUpload} />
      <p className={styles.status} aria-live="polite">
        {status}
      </p>
    </Section>
  );
}
