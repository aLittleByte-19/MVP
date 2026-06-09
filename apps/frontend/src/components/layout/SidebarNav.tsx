import type { LucideIcon } from "lucide-react";
import styles from "./SidebarNav.module.css";

export type SidebarNavChild = {
  label: string;
  targetId: string;
};

export type SidebarNavItem<TValue extends string> = {
  children?: SidebarNavChild[];
  icon?: LucideIcon;
  id: TValue;
  label: string;
};

export type SidebarNavGroup<TValue extends string> = {
  items: SidebarNavItem<TValue>[];
  title: string;
};

type SidebarNavProps<TValue extends string> = {
  activeId: TValue;
  brandLabel?: string;
  groups: SidebarNavGroup<TValue>[];
  logoAlt: string;
  logoSrc: string;
  navLabel: string;
  onSelect: (id: TValue, targetId?: string) => void;
};

export function SidebarNav<TValue extends string>({
  activeId,
  brandLabel,
  groups,
  logoAlt,
  logoSrc,
  navLabel,
  onSelect
}: SidebarNavProps<TValue>) {
  return (
    <aside className={styles.sidebar} aria-label={navLabel}>
      <div className={styles.brand}>
        <img src={logoSrc} alt={logoAlt} />
        {brandLabel ? <span>{brandLabel}</span> : null}
      </div>
      <nav className={styles.nav}>
        {groups.map((group) => (
          <div className={styles.section} key={group.title}>
            <p className={styles.sectionTitle}>{group.title}</p>
            {group.items.map((item) => {
              const Icon = item.icon;
              const isActive = item.id === activeId;

              return (
                <div className={styles.itemGroup} key={item.id}>
                  <button
                    className={isActive ? `${styles.item} ${styles.active}` : styles.item}
                    type="button"
                    aria-current={isActive ? "page" : undefined}
                    onClick={() => onSelect(item.id)}
                  >
                    {Icon ? <Icon aria-hidden="true" size={18} /> : null}
                    <span>{item.label}</span>
                  </button>
                  {item.children?.map((child) => (
                    <button
                      className={isActive ? `${styles.subitem} ${styles.subitemActive}` : styles.subitem}
                      key={child.targetId}
                      type="button"
                      onClick={() => onSelect(item.id, child.targetId)}
                    >
                      {child.label}
                    </button>
                  ))}
                </div>
              );
            })}
          </div>
        ))}
      </nav>
    </aside>
  );
}
