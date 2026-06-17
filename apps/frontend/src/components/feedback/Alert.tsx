import styles from "./Alert.module.css";

type AlertProps = {
  title: string;
  children: React.ReactNode;
};

export function Alert({ title, children }: AlertProps) {
  return (
    <section className={styles.alert} role="alert" aria-live="polite">
      <h2>{title}</h2>
      <p>{children}</p>
    </section>
  );
}
