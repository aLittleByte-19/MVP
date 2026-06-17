import styles from "./SelectField.module.css";

type SelectFieldProps = React.SelectHTMLAttributes<HTMLSelectElement> & {
  label: string;
  options: readonly string[];
};

export function SelectField({ id, label, options, ...props }: SelectFieldProps) {
  const fieldId = id ?? `field-${label.toLowerCase().replaceAll(" ", "-")}`;

  return (
    <label className={styles.field} htmlFor={fieldId}>
      <span>{label}</span>
      <select id={fieldId} {...props}>
        {options.map((option) => (
          <option key={option}>{option}</option>
        ))}
      </select>
    </label>
  );
}
