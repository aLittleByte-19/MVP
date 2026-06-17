import type { CommunicationRecord } from "../types";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Section } from "../../../components/layout/Section";
import { CommunicationStatusCard } from "./CommunicationStatusCard";

type CommunicationApprovalPanelProps = {
  history: CommunicationRecord[];
  selectedId?: number | null;
  onSelect?: (communicationId: number) => void;
};

export function CommunicationApprovalPanel({ history, selectedId, onSelect }: CommunicationApprovalPanelProps) {
  return (
    <Section id="assistant-history" title="Storico contenuti">
      {history.length ? (
        history.map((communication) => (
          <CommunicationStatusCard
            communication={communication}
            key={communication.id}
            isSelected={communication.id === selectedId}
            onSelect={onSelect}
          />
        ))
      ) : (
        <EmptyState>Le bozze generate compariranno qui.</EmptyState>
      )}
    </Section>
  );
}
