import styles from "./DescriptionList.module.css";

export type DescriptionListItem = {
  label: string;
  value: React.ReactNode;
};

export function DescriptionList({ items }: { items: DescriptionListItem[] }) {
  return (
    <dl className={styles.list}>
      {items.map((item) => (
        <div className={styles.item} key={item.label}>
          <dt>{item.label}</dt>
          <dd>{item.value}</dd>
        </div>
      ))}
    </dl>
  );
}
