import { StatusBadge } from "../../../components/data-display/StatusBadge";
import { formatFallback } from "../../../lib/formatters";
import type { CommunicationRecord } from "../types";
import styles from "./CommunicationStatusCard.module.css";

type CommunicationStatusCardProps = {
  communication: CommunicationRecord;
  isSelected?: boolean;
  onSelect?: (communicationId: number) => void;
};

export function CommunicationStatusCard({ communication, isSelected, onSelect }: CommunicationStatusCardProps) {
  return (
    <button
      type="button"
      className={`${styles.card} ${isSelected ? styles.isSelected : ""}`.trim()}
      aria-pressed={isSelected}
      onClick={() => onSelect?.(communication.id)}
    >
      <StatusBadge>{communication.status}</StatusBadge>
      <span className={styles.title}>{communication.title}</span>
      <p>{formatFallback(communication.createdAt)}</p>
    </button>
  );
}
