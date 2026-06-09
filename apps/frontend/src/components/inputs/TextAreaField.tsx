import styles from "./TextAreaField.module.css";

type TextAreaFieldProps = React.TextareaHTMLAttributes<HTMLTextAreaElement> & {
  label: string;
};

export function TextAreaField({ id, label, ...props }: TextAreaFieldProps) {
  const fieldId = id ?? `field-${label.toLowerCase().replaceAll(" ", "-")}`;

  return (
    <label className={styles.field} htmlFor={fieldId}>
      <span>{label}</span>
      <textarea id={fieldId} {...props} />
    </label>
  );
}
