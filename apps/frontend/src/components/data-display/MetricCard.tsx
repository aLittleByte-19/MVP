import styles from "./MetricCard.module.css";

type MetricCardProps = {
  value: string | number;
  label: string;
};

export function MetricCard({ value, label }: MetricCardProps) {
  return (
    <article className={styles.card}>
      <strong className={styles.value}>{value}</strong>
      <span className={styles.label}>{label}</span>
    </article>
  );
}
