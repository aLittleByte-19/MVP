import type { SubDocument } from "../../../api/generated/model";
import { StatusBadge } from "../../../components/data-display/StatusBadge";
import { getReviewStatusTone } from "../../../lib/status";
import styles from "./DocumentStatusTimeline.module.css";

export function DocumentStatusTimeline({ documentItem }: { documentItem: SubDocument }) {
  return (
    <ul className={styles.timeline} aria-label="Stato documento">
      <li>
        <StatusBadge tone={getReviewStatusTone(documentItem.reviewStatus, documentItem.error)}>
          {documentItem.reviewStatusLabel}
        </StatusBadge>
      </li>
      {documentItem.pages ? (
        <li>
          <StatusBadge>{documentItem.pages} pagine</StatusBadge>
        </li>
      ) : null}
      {documentItem.type ? (
        <li>
          <StatusBadge>{documentItem.type}</StatusBadge>
        </li>
      ) : null}
    </ul>
  );
}
