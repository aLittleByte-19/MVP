import styles from "./Section.module.css";

type SectionProps = {
  actions?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  id?: string;
  title?: string;
};

export function Section({ actions, children, className, id, title }: SectionProps) {
  return (
    <section className={[styles.section, className].filter(Boolean).join(" ")} id={id}>
      {title || actions ? (
        <div className={styles.heading}>
          {title ? <h2>{title}</h2> : <span />}
          {actions}
        </div>
      ) : null}
      {children}
    </section>
  );
}
