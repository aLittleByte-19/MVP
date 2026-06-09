import type { CommunicationRecord } from "../types";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Section } from "../../../components/layout/Section";
import { CommunicationStatusCard } from "./CommunicationStatusCard";

export function CommunicationApprovalPanel({ history }: { history: CommunicationRecord[] }) {
  return (
    <Section id="assistant-history" title="Storico contenuti">
      {history.length ? (
        history.map((communication) => (
          <CommunicationStatusCard communication={communication} key={communication.id} />
        ))
      ) : (
        <EmptyState>Le bozze generate compariranno qui.</EmptyState>
      )}
    </Section>
  );
}
