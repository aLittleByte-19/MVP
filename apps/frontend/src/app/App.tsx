import { Moon, Sun } from "lucide-react";
import { useCallback, useMemo, useState } from "react";
import alittlebyteLogo from "../assets/alittlebyte-logo.png";
import { ErrorState } from "../components/feedback/ErrorState";
import { AppShell } from "../components/layout/AppShell";
import { PageHeader } from "../components/layout/PageHeader";
import { Section } from "../components/layout/Section";
import { SidebarNav } from "../components/layout/SidebarNav";
import { CommunicationGeneratorPanel } from "../features/assistant/components/CommunicationGeneratorPanel";
import { GeneratedCommunicationPreview } from "../features/assistant/components/GeneratedCommunicationPreview";
import { useGenerateCommunication } from "../features/assistant/hooks/useGenerateCommunication";
import { CommunicationApprovalPanel } from "../features/communications/components/CommunicationApprovalPanel";
import { DocumentList } from "../features/documents/components/DocumentList";
import { DocumentUploadPanel } from "../features/documents/components/DocumentUploadPanel";
import { SubDocumentList } from "../features/documents/components/SubDocumentList";
import { useDeleteDocument } from "../features/documents/hooks/useDeleteDocument";
import { useDocumentUpload } from "../features/documents/hooks/useDocumentUpload";
import { useDocuments } from "../features/documents/hooks/useDocuments";
import { useReviewSubDocument } from "../features/documents/hooks/useReviewSubDocument";
import { MetricsPanel } from "../features/observability/components/MetricsPanel";
import { SystemStatePanel } from "../features/state/components/SystemStatePanel";
import { usePocState } from "../features/state/hooks/usePocState";
import { getErrorMessage } from "../lib/errors";
import { useScrollSpy } from "../hooks/useScrollSpy";
import { useTheme } from "../hooks/useTheme";
import { pocNavGroups, pocViewTitles, type PocView } from "./routes";
import styles from "./App.module.css";

export function App() {
  const [activeView, setActiveView] = useState<PocView>("overview");
  const { theme, toggleTheme } = useTheme();
  const stateQuery = usePocState();
  const state = stateQuery.data;
  const documents = state?.copilot.documents ?? [];
  const { selectDocument, selectedDocument, selectedDocumentId } = useDocuments(documents);
  const assistant = useGenerateCommunication(() => setActiveView("assistant"));
  const upload = useDocumentUpload({ onDocumentReceived: selectDocument });
  const deleteDocument = useDeleteDocument(() => selectDocument(null));
  const reviewDocument = useReviewSubDocument(selectDocument);

  const activeChildIds = useMemo(
    () =>
      pocNavGroups
        .flatMap((group) => group.items)
        .find((item) => item.id === activeView)
        ?.children?.map((child) => child.targetId) ?? [],
    [activeView],
  );
  const activeChildId = useScrollSpy(activeChildIds);

  const navigate = useCallback((view: PocView, targetId?: string) => {
    setActiveView(view);

    window.requestAnimationFrame(() => {
      const target = targetId ? document.getElementById(targetId) : document.getElementById("poc-topbar");
      target?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }, []);

  return (
    <AppShell
      sidebar={
        <SidebarNav
          activeId={activeView}
          activeChildId={activeChildId}
          groups={pocNavGroups}
          logoAlt="Alittlebyte"
          logoSrc={alittlebyteLogo}
          navLabel="Navigazione applicativa"
          onSelect={navigate}
        />
      }
      header={
        <PageHeader
          eyebrow="NEXUM"
          title={pocViewTitles[activeView]}
          actions={
            <button
              className={styles.themeToggle}
              type="button"
              aria-label={theme === "dark" ? "Attiva tema chiaro" : "Attiva tema scuro"}
              aria-pressed={theme === "dark"}
              onClick={toggleTheme}
            >
              <Sun aria-hidden="true" size={16} />
              <Moon aria-hidden="true" size={16} />
            </button>
          }
        />
      }
    >
      {stateQuery.isError ? <ErrorState message={getErrorMessage(stateQuery.error)} /> : null}

      {activeView === "overview" ? (
        <SystemStatePanel isLoading={stateQuery.isLoading} state={state} onNavigate={navigate} />
      ) : null}

      {activeView === "assistant" ? (
        <section className={styles.view} aria-label="AI Assistant Generativo">
          <CommunicationGeneratorPanel
            isGenerating={assistant.isGenerating}
            onGenerate={assistant.generate}
            status={assistant.status}
          />
          <GeneratedCommunicationPreview draft={assistant.draft} />
          <CommunicationApprovalPanel history={state?.assistant.history ?? []} />
        </section>
      ) : null}

      {activeView === "copilot" ? (
        <section className={styles.view} aria-label="AI Co-Pilot per i CdL">
          <DocumentUploadPanel
            isUploading={upload.isUploading}
            onUpload={upload.upload}
            status={upload.status}
          />
          <Section id="copilot-documents" title="Storico invii" actions={<span>{documents.length} record</span>}>
            <DocumentList
              documents={documents}
              onSelect={selectDocument}
              selectedDocumentId={selectedDocumentId}
            />
          </Section>
          <SubDocumentList
            documentItem={selectedDocument}
            isDeleting={deleteDocument.isDeleting}
            isSavingReview={reviewDocument.isSavingReview}
            onDelete={deleteDocument.deleteDocument}
            onMarkReviewed={reviewDocument.markReviewed}
            onSaveReview={reviewDocument.saveReview}
            reviewError={reviewDocument.reviewError}
          />
          <Section id="copilot-metrics" title="Qualita e performance OCR">
            <MetricsPanel isLoading={stateQuery.isLoading} metrics={state?.copilot.metrics} />
          </Section>
        </section>
      ) : null}
    </AppShell>
  );
}
