import styles from "./StatusBadge.module.css";

type StatusBadgeProps = {
  children: React.ReactNode;
  tone?: "neutral" | "success" | "warning" | "danger";
};

export function StatusBadge({ children, tone = "neutral" }: StatusBadgeProps) {
  const className = tone === "neutral" ? styles.badge : `${styles.badge} ${styles[tone]}`;

  return <span className={className}>{children}</span>;
}
