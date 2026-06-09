import type { PocState } from "../../../api/generated/model";
import { DataTable } from "../../../components/data-display/DataTable";
import { MetricCard } from "../../../components/data-display/MetricCard";
import { StatusBadge } from "../../../components/data-display/StatusBadge";
import { EmptyState } from "../../../components/feedback/EmptyState";
import { Button } from "../../../components/inputs/Button";
import { Section } from "../../../components/layout/Section";
import { MetricsPanel } from "../../observability/components/MetricsPanel";
import styles from "./SystemStatePanel.module.css";

type SystemStatePanelProps = {
  isLoading?: boolean;
  onNavigate: (view: "overview" | "assistant" | "copilot", targetId?: string) => void;
  state?: PocState;
};

export function SystemStatePanel({ isLoading, onNavigate, state }: SystemStatePanelProps) {
  const communications = state?.assistant.history ?? [];
  const documents = state?.copilot.documents ?? [];
  const documentsToReview = documents.filter((documentItem) => documentItem.error).length;
  const readyDocuments = documents.filter((documentItem) => !documentItem.error).length;

  return (
    <section className={styles.view} aria-label="Overview operativa">
      <Section className={styles.hero}>
        <p className={styles.eyebrow}>Console operativa</p>
        <h2>Gestione assistita di comunicazioni interne e documenti del personale.</h2>
        <p>
          NEXUM supporta redattori HR e operatori CdL nelle attivita quotidiane: preparazione dei contenuti,
          classificazione documentale, verifica degli esiti e tracciamento delle consegne.
        </p>
        <div className={styles.buttonRow}>
          <Button onClick={() => onNavigate("assistant", "assistant-compose")}>Crea contenuto</Button>
          <Button variant="secondary" onClick={() => onNavigate("copilot", "copilot-upload")}>
            Carica documenti
          </Button>
        </div>
      </Section>

      <Section id="overview-modules" title="Da dove partire">
        <ul className={styles.flowList}>
          <li>
            <strong>AI Assistant Generativo</strong>
            <span>Scrivi il contenuto da comunicare, scegli tono e stile, poi revisiona la bozza proposta.</span>
          </li>
          <li>
            <strong>AI Co-Pilot per CdL</strong>
            <span>Carica un PDF e consulta metadati, destinatari e livello di confidenza dopo l'analisi.</span>
          </li>
          <li>
            <strong>Metriche operative</strong>
            <span>Controlla volumi, qualita e stato delle attivita direttamente nelle pagine degli strumenti.</span>
          </li>
        </ul>
      </Section>

      <Section id="overview-priorities" title="Priorita essenziali">
        <div className={styles.priorityGrid}>
          <MetricCard label="Bozze generate" value={communications.length} />
          <MetricCard label="Documenti da verificare" value={documentsToReview} />
          <MetricCard label="Documenti pronti" value={readyDocuments} />
        </div>
      </Section>

      <Section title="Metriche strumenti">
        <div className={styles.overviewGrid}>
          <div className={styles.metricGroup}>
            <h3>AI Assistant</h3>
            <MetricsPanel isLoading={isLoading} metrics={state?.assistant.metrics} />
          </div>
          <div className={styles.metricGroup}>
            <h3>Co-Pilot documentale</h3>
            <MetricsPanel isLoading={isLoading} metrics={state?.copilot.metrics} />
          </div>
        </div>
      </Section>

      <Section title="Attivita recenti">
        {communications.length ? (
          <DataTable
            rows={communications}
            getRowKey={(row) => String(row.id)}
            columns={[
              { key: "title", header: "Titolo", render: (row) => <strong>{row.title}</strong> },
              { key: "status", header: "Stato", render: (row) => <StatusBadge>{row.status}</StatusBadge> },
              { key: "createdAt", header: "Creazione", render: (row) => row.createdAt }
            ]}
          />
        ) : (
          <EmptyState>Le nuove attivita compariranno qui.</EmptyState>
        )}
      </Section>
    </section>
  );
}
