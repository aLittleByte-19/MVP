import { UploadCloud } from "lucide-react";
import styles from "./FileDropzone.module.css";

type FileDropzoneProps = {
  disabled?: boolean;
  label: string;
  onFileSelected: (file: File) => void;
};

export function FileDropzone({ disabled, label, onFileSelected }: FileDropzoneProps) {
  return (
    <label className={styles.dropzone}>
      <UploadCloud aria-hidden="true" />
      <span>{label}</span>
      <input
        type="file"
        accept="application/pdf"
        disabled={disabled}
        onChange={(event) => {
          const file = event.currentTarget.files?.[0];

          if (file) {
            onFileSelected(file);
            event.currentTarget.value = "";
          }
        }}
      />
    </label>
  );
}
