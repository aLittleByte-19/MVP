import { ArrowUp } from "lucide-react";
import { Button } from "../inputs/Button";
import styles from "./AppShell.module.css";

type AppShellProps = {
  header: React.ReactNode;
  sidebar: React.ReactNode;
  children: React.ReactNode;
};

export function AppShell({ header, sidebar, children }: AppShellProps) {
  return (
    <div className={styles.shell}>
      {sidebar}
      <div className={styles.workspace}>
        {header}
        <main className={styles.main}>{children}</main>
        <Button
          className={styles.backToTop}
          variant="icon"
          aria-label="Torna su"
          onClick={() => document.getElementById("poc-topbar")?.scrollIntoView({ behavior: "smooth" })}
        >
          <ArrowUp aria-hidden="true" />
        </Button>
      </div>
    </div>
  );
}
