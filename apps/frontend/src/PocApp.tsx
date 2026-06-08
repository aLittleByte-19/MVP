import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ArrowUp,
  Bot,
  CheckCircle2,
  FileText,
  LayoutDashboard,
  Moon,
  Send,
  Sun,
  Trash2,
  UploadCloud
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { useForm } from "react-hook-form";
import alittlebyteLogo from "./assets/alittlebyte-logo.png";
import {
  deletePocSubDocument,
  generatePocCommunication,
  getPocState,
  uploadPocDocument
} from "./api/pocApi";
import type {
  GenerateCommunicationRequest,
  PocState,
  SubDocument
} from "./api/generated/model";

type PocView = "overview" | "assistant" | "copilot";

type PocDraftForm = GenerateCommunicationRequest;

const pocViews: Array<{ id: PocView; label: string; icon: typeof LayoutDashboard }> = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "assistant", label: "AI Assistant", icon: Bot },
  { id: "copilot", label: "Co-Pilot CdL", icon: FileText }
];

const pocViewTitles: Record<PocView, string> = {
  overview: "Overview operativa",
  assistant: "AI Assistant Generativo",
  copilot: "AI Co-Pilot per i CdL"
};

const pocTones = ["Chiaro e diretto", "Più istituzionale", "Più sintetico", "Empatico", "Tecnico"] as const;
const pocStyles = ["Testo informativo", "Avviso operativo", "Aggiornamento breve"] as const;

function getPocErrorMessage(error: unknown): string {
  if (error instanceof Error) {
    return error.message;
  }

  if (typeof error === "object" && error !== null && "error" in error) {
    const payload = error as { error?: { message?: string } };
    return payload.error?.message || "Operazione non disponibile.";
  }

  return "Operazione non disponibile.";
}

function getPocDocumentId(documentId: string): number {
  return Number.parseInt(documentId.replace("sub-", ""), 10);
}

function PocMetricList({ metrics }: { metrics: PocState["assistant"]["metrics"] }) {
  return (
    <ul className="poc-metric-list">
      {metrics.map((metric) => (
        <li key={metric.label}>
          <strong>{metric.value}</strong>
          <span>{metric.label}</span>
        </li>
      ))}
    </ul>
  );
}

function PocEmptyState({ children }: { children: string }) {
  return <p className="poc-empty-state">{children}</p>;
}

export function PocApp() {
  const pocQueryClient = useQueryClient();
  const [pocTheme, setPocTheme] = useState(() => window.localStorage.getItem("poc-theme") || "light");
  const [pocActiveView, setPocActiveView] = useState<PocView>("overview");
  const [pocSelectedDocumentId, setPocSelectedDocumentId] = useState<string | null>(null);
  const [pocAssistantStatus, setPocAssistantStatus] = useState("In attesa di istruzioni.");
  const [pocUploadStatus, setPocUploadStatus] = useState("Nessun caricamento in corso.");
  const [pocGeneratedDraft, setPocGeneratedDraft] = useState<{ title: string; body: string; status: string } | null>(null);

  const pocStateQuery = useQuery({
    queryKey: ["poc-state"],
    queryFn: () => getPocState()
  });

  const pocState = pocStateQuery.data;
  const pocDocuments = pocState?.copilot.documents ?? [];
  const pocSelectedDocument = useMemo(() => {
    return pocDocuments.find((documentItem) => documentItem.id === pocSelectedDocumentId) ?? pocDocuments[0] ?? null;
  }, [pocDocuments, pocSelectedDocumentId]);

  const pocDraftForm = useForm<PocDraftForm>({
    defaultValues: {
      prompt: "",
      tone: "Chiaro e diretto",
      style: "Testo informativo"
    }
  });

  const pocGenerateMutation = useMutation({
    mutationFn: (payload: PocDraftForm) => generatePocCommunication(payload),
    onSuccess: (response) => {
      setPocGeneratedDraft({
        title: response.communication.title,
        body: response.communication.body,
        status: response.communication.status
      });
      setPocAssistantStatus(response.message);
      pocQueryClient.setQueryData(["poc-state"], response.state);
      setPocActiveView("assistant");
    },
    onError: (error) => {
      setPocAssistantStatus(getPocErrorMessage(error));
    }
  });

  const pocUploadMutation = useMutation({
    mutationFn: (file: File) => uploadPocDocument({ document: file }),
    onSuccess: (response) => {
      setPocUploadStatus(response.message);
      const pocEvents = new EventSource(response.streamUrl);
      pocEvents.addEventListener("document", (event) => {
        const documentItem = JSON.parse((event as MessageEvent).data) as SubDocument;
        pocQueryClient.setQueryData<PocState>(["poc-state"], (current) => {
          if (!current) {
            return current;
          }

          const nextDocuments = [
            documentItem,
            ...current.copilot.documents.filter((item) => item.id !== documentItem.id)
          ];

          return {
            ...current,
            copilot: {
              ...current.copilot,
              documents: nextDocuments
            }
          };
        });
        setPocSelectedDocumentId(documentItem.id);
      });
      pocEvents.addEventListener("done", (event) => {
        const payload = JSON.parse((event as MessageEvent).data) as { state?: PocState };
        if (payload.state) {
          pocQueryClient.setQueryData(["poc-state"], payload.state);
        }
        setPocUploadStatus("Elaborazione completata.");
        pocEvents.close();
      });
      pocEvents.addEventListener("error", () => {
        setPocUploadStatus("Elaborazione non disponibile. Controlla lo stato del documento.");
        pocEvents.close();
        void pocQueryClient.invalidateQueries({ queryKey: ["poc-state"] });
      });
    },
    onError: (error) => {
      setPocUploadStatus(getPocErrorMessage(error));
    }
  });

  const pocDeleteMutation = useMutation({
    mutationFn: (documentId: string) => deletePocSubDocument(getPocDocumentId(documentId)),
    onSuccess: (response) => {
      pocQueryClient.setQueryData(["poc-state"], response.state);
      setPocSelectedDocumentId(null);
    }
  });

  useEffect(() => {
    document.documentElement.dataset.pocTheme = pocTheme;
    window.localStorage.setItem("poc-theme", pocTheme);
  }, [pocTheme]);

  useEffect(() => {
    if (pocSelectedDocument?.id) {
      setPocSelectedDocumentId(pocSelectedDocument.id);
    }
  }, [pocSelectedDocument?.id]);

  const pocDraftBodyLength = pocGeneratedDraft?.body.length ?? 0;

  return (
    <div className="poc-shell">
      <aside className="poc-sidebar" aria-label="Navigazione PoC">
        <div className="poc-brand">
          <img src={alittlebyteLogo} alt="Alittlebyte" />
          <span>Alittlebyte PoC</span>
        </div>

        <nav className="poc-nav">
          {pocViews.map((view) => {
            const Icon = view.icon;
            return (
              <button
                key={view.id}
                className={view.id === pocActiveView ? "poc-nav-item poc-nav-item-active" : "poc-nav-item"}
                type="button"
                onClick={() => setPocActiveView(view.id)}
              >
                <Icon aria-hidden="true" size={18} />
                <span>{view.label}</span>
              </button>
            );
          })}
        </nav>
      </aside>

      <div className="poc-workspace">
        <header className="poc-topbar" id="poc-topbar">
          <div>
            <p className="poc-eyebrow">Alittlebyte PoC</p>
            <h1>{pocViewTitles[pocActiveView]}</h1>
          </div>
          <button
            className="poc-icon-button"
            type="button"
            aria-label={pocTheme === "dark" ? "Attiva tema chiaro" : "Attiva tema scuro"}
            onClick={() => setPocTheme((current) => (current === "dark" ? "light" : "dark"))}
          >
            {pocTheme === "dark" ? <Sun aria-hidden="true" /> : <Moon aria-hidden="true" />}
          </button>
        </header>

        {pocStateQuery.isError ? (
          <section className="poc-panel poc-panel-alert">
            <h2>Servizio non disponibile</h2>
            <p>{getPocErrorMessage(pocStateQuery.error)}</p>
          </section>
        ) : null}

        <main className="poc-view-stack">
          {pocActiveView === "overview" && (
            <section className="poc-view" aria-labelledby="poc-overview-title">
              <div className="poc-panel poc-panel-hero">
                <div>
                  <p className="poc-eyebrow">Stato operativo</p>
                  <h2 id="poc-overview-title">Flussi principali della PoC</h2>
                </div>
                <div className="poc-overview-grid">
                  <div>
                    <h3>Assistant</h3>
                    {pocState ? <PocMetricList metrics={pocState.assistant.metrics} /> : <PocEmptyState>Caricamento metriche.</PocEmptyState>}
                  </div>
                  <div>
                    <h3>Co-Pilot documentale</h3>
                    {pocState ? <PocMetricList metrics={pocState.copilot.metrics} /> : <PocEmptyState>Caricamento metriche.</PocEmptyState>}
                  </div>
                </div>
              </div>

              <div className="poc-panel">
                <h2>Attività recenti</h2>
                {pocState?.assistant.history.length ? (
                  <ul className="poc-history-list">
                    {pocState.assistant.history.map((item) => (
                      <li key={item.id}>
                        <strong>{item.title}</strong>
                        <span>{item.status} · {item.createdAt}</span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <PocEmptyState>Nessuna comunicazione generata.</PocEmptyState>
                )}
              </div>
            </section>
          )}

          {pocActiveView === "assistant" && (
            <section className="poc-view poc-two-column" aria-labelledby="poc-assistant-title">
              <form
                className="poc-panel poc-form-panel"
                onSubmit={pocDraftForm.handleSubmit((payload) => pocGenerateMutation.mutate(payload))}
              >
                <h2 id="poc-assistant-title">Genera comunicazione</h2>
                <label className="poc-field">
                  <span>Prompt</span>
                  <textarea
                    rows={8}
                    {...pocDraftForm.register("prompt", { required: true, minLength: 12 })}
                    placeholder="Descrivi la comunicazione interna da generare."
                  />
                </label>
                <div className="poc-field-row">
                  <label className="poc-field">
                    <span>Tono</span>
                    <select {...pocDraftForm.register("tone")}>
                      {pocTones.map((tone) => (
                        <option key={tone}>{tone}</option>
                      ))}
                    </select>
                  </label>
                  <label className="poc-field">
                    <span>Stile</span>
                    <select {...pocDraftForm.register("style")}>
                      {pocStyles.map((style) => (
                        <option key={style}>{style}</option>
                      ))}
                    </select>
                  </label>
                </div>
                <button className="poc-primary-button" type="submit" disabled={pocGenerateMutation.isPending}>
                  <Send aria-hidden="true" size={18} />
                  <span>{pocGenerateMutation.isPending ? "Generazione" : "Genera"}</span>
                </button>
                <p className="poc-status">{pocAssistantStatus}</p>
              </form>

              <section className="poc-panel">
                <div className="poc-panel-heading">
                  <h2>Bozza</h2>
                  <span>{pocDraftBodyLength} caratteri</span>
                </div>
                {pocGeneratedDraft ? (
                  <article className="poc-generated-draft">
                    <span>{pocGeneratedDraft.status}</span>
                    <h3>{pocGeneratedDraft.title}</h3>
                    <p>{pocGeneratedDraft.body}</p>
                  </article>
                ) : (
                  <PocEmptyState>La bozza generata apparirà qui.</PocEmptyState>
                )}
              </section>
            </section>
          )}

          {pocActiveView === "copilot" && (
            <section className="poc-view poc-two-column poc-copilot-layout" aria-labelledby="poc-copilot-title">
              <div className="poc-panel">
                <h2 id="poc-copilot-title">Documenti</h2>
                <label className="poc-upload-dropzone">
                  <UploadCloud aria-hidden="true" />
                  <span>Carica PDF</span>
                  <input
                    type="file"
                    accept="application/pdf"
                    onChange={(event) => {
                      const file = event.currentTarget.files?.[0];
                      if (file) {
                        setPocUploadStatus("Upload avviato.");
                        pocUploadMutation.mutate(file);
                      }
                    }}
                  />
                </label>
                <p className="poc-status">{pocUploadStatus}</p>

                {pocDocuments.length ? (
                  <ul className="poc-document-list">
                    {pocDocuments.map((documentItem) => (
                      <li key={documentItem.id}>
                        <button
                          className={documentItem.id === pocSelectedDocument?.id ? "poc-document-button poc-document-button-active" : "poc-document-button"}
                          type="button"
                          onClick={() => setPocSelectedDocumentId(documentItem.id)}
                        >
                          <span>
                            <strong>{documentItem.title || documentItem.file || "Documento rilevato"}</strong>
                            <small>{documentItem.employee || documentItem.company || "Dati in revisione"}</small>
                          </span>
                          {documentItem.error ? <span className="poc-badge poc-badge-warning">Errore</span> : <CheckCircle2 aria-hidden="true" size={18} />}
                        </button>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <PocEmptyState>Nessun documento elaborato.</PocEmptyState>
                )}
              </div>

              <section className="poc-panel poc-document-detail">
                {pocSelectedDocument ? (
                  <>
                    <div className="poc-panel-heading">
                      <h2>{pocSelectedDocument.title || "Documento"}</h2>
                      <button
                        className="poc-icon-button"
                        type="button"
                        aria-label="Elimina documento"
                        disabled={pocDeleteMutation.isPending}
                        onClick={() => pocDeleteMutation.mutate(pocSelectedDocument.id)}
                      >
                        <Trash2 aria-hidden="true" />
                      </button>
                    </div>
                    <dl className="poc-detail-grid">
                      <div><dt>Dipendente</dt><dd>{pocSelectedDocument.employee || "Non disponibile"}</dd></div>
                      <div><dt>Azienda</dt><dd>{pocSelectedDocument.company || "Non disponibile"}</dd></div>
                      <div><dt>Data</dt><dd>{pocSelectedDocument.date || "Non disponibile"}</dd></div>
                      <div><dt>Confidenza</dt><dd>{pocSelectedDocument.confidence ?? "Da verificare"}</dd></div>
                    </dl>
                    <iframe
                      className="poc-preview-frame"
                      title="Anteprima documento"
                      src={pocSelectedDocument.previewUrl}
                    />
                    <ul className="poc-preview-lines">
                      {pocSelectedDocument.previewLines.map((line) => (
                        <li key={line}>{line}</li>
                      ))}
                    </ul>
                  </>
                ) : (
                  <PocEmptyState>Seleziona un documento per visualizzare il dettaglio.</PocEmptyState>
                )}
              </section>
            </section>
          )}
        </main>

        <button
          className="poc-back-to-top"
          type="button"
          aria-label="Torna su"
          onClick={() => document.getElementById("poc-topbar")?.scrollIntoView({ behavior: "smooth" })}
        >
          <ArrowUp aria-hidden="true" />
        </button>
      </div>
    </div>
  );
}
