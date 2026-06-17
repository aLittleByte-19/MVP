import { StatusBadge } from "../../../components/data-display/StatusBadge";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Section } from "../../../components/layout/Section";
import type { GeneratedDraft } from "../types";
import styles from "./GeneratedCommunicationPreview.module.css";

export function GeneratedCommunicationPreview({ draft }: { draft: GeneratedDraft | null }) {
  const bodyLength = draft?.body.length ?? 0;

  return (
    <Section
      id="assistant-review"
      title="Controlla il contenuto"
      actions={<span className={styles.meta}>{bodyLength} caratteri</span>}
    >
      {draft ? (
        <article className={styles.draft}>
          <div className={styles.cover}>
            <span>Bozza generata</span>
          </div>
          <label className={styles.field}>
            <span>Titolo</span>
            <input readOnly value={draft.title} />
          </label>
          <label className={styles.field}>
            <span>Testo</span>
            <textarea readOnly rows={7} value={draft.body} />
          </label>
          <div className={styles.footer}>
            <StatusBadge>{draft.status}</StatusBadge>
            <span>Creato da AI Assistant · anteprima in sola lettura</span>
          </div>
        </article>
      ) : (
        <EmptyState>La bozza generata apparira qui.</EmptyState>
      )}
    </Section>
  );
}
