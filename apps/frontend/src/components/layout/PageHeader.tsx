import styles from "./PageHeader.module.css";

type PageHeaderProps = {
  eyebrow: string;
  title: string;
  actions?: React.ReactNode;
};

export function PageHeader({ actions, eyebrow, title }: PageHeaderProps) {
  return (
    <header className={styles.header} id="poc-topbar">
      <div>
        <p className={styles.eyebrow}>{eyebrow}</p>
        <h1>{title}</h1>
      </div>
      {actions}
    </header>
  );
}
