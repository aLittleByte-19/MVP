import styles from "./LoadingState.module.css";

export function LoadingState({ label = "Caricamento in corso." }: { label?: string }) {
  return (
    <p className={styles.loading} role="status" aria-live="polite">
      {label}
    </p>
  );
}
