import type { Metric } from "../../../api/generated/model";
import { MetricCard } from "../../../components/data-display/MetricCard";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { LoadingState } from "../../../components/feedback/LoadingState";
import { useMetrics } from "../hooks/useMetrics";
import styles from "./MetricsPanel.module.css";

type MetricsPanelProps = {
  isLoading?: boolean;
  metrics?: Metric[];
};

export function MetricsPanel({ isLoading, metrics }: MetricsPanelProps) {
  const visibleMetrics = useMetrics(metrics);

  if (isLoading) {
    return <LoadingState label="Caricamento metriche." />;
  }

  if (!visibleMetrics.length) {
    return <EmptyState>Nessuna metrica disponibile.</EmptyState>;
  }

  return (
    <div className={styles.grid}>
      {visibleMetrics.map((metric) => (
        <MetricCard key={metric.label} label={metric.label} value={metric.value} />
      ))}
    </div>
  );
}
