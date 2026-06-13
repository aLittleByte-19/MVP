import { StatusBadge } from "../../../components/data-display/StatusBadge";
import { formatFallback } from "../../../lib/formatters";
import type { CommunicationRecord } from "../types";
import styles from "./CommunicationStatusCard.module.css";

export function CommunicationStatusCard({ communication }: { communication: CommunicationRecord }) {
  return (
    <article className={styles.card}>
      <StatusBadge>{communication.status}</StatusBadge>
      <h3>{communication.title}</h3>
      <p>{formatFallback(communication.createdAt)}</p>
    </article>
  );
}
