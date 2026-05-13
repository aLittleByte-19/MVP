const views = {
  overview: "Overview operativa",
  assistant: "AI Assistant Generativo",
  copilot: "AI Co-Pilot per i CdL"
};

const apiRoutes = {
  state: "/poc/api/state",
  communications: "/poc/api/communications",
  documentOcr: "/poc/api/documents/ocr",
  documentDelete: (id) => `/poc/api/documents/${String(id).replace("sub-", "")}`
};

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
const mainNavItems = document.querySelectorAll(".nav-item");
const subNavItems = document.querySelectorAll(".nav-subitem");
const sections = document.querySelectorAll(".view");
const titleNode = document.getElementById("view-title");
const themeToggle = document.getElementById("theme-toggle");
const backToTopButton = document.getElementById("back-to-top");
const storedTheme = window.localStorage.getItem("nexum-theme");
const systemPrefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
let currentTheme = storedTheme || (systemPrefersDark ? "dark" : "light");
let currentView = "overview";
let documents = [];
let activeDocumentId = null;

function setText(node, value) {
  if (node) {
    node.textContent = value;
  }
}

function setValue(node, value) {
  if (node) {
    node.value = value ?? "";
  }
}

function renderMetricList(list, values) {
  if (!list) {
    return;
  }

  list.replaceChildren();
  values.forEach(([value, label]) => {
    const item = document.createElement("li");
    const strong = document.createElement("strong");
    const span = document.createElement("span");
    strong.textContent = value;
    span.textContent = label;
    item.append(strong, span);
    list.append(item);
  });
}

function humanApiError(error) {
  if (error?.errors) {
    return Object.values(error.errors).flat().join(" ");
  }

  const message = String(error?.message || "");

  if (error?.status === 419 || message.toLowerCase().includes("csrf token mismatch")) {
    return "La pagina è rimasta aperta troppo a lungo. Ricaricala e riprova l'operazione.";
  }

  if (message.toLowerCase().includes("sessione non inizializzata")) {
    return "La pagina deve essere ricaricata prima di continuare.";
  }

  if (error?.status === 413) {
    return "Il file selezionato è troppo grande per questa PoC.";
  }

  if (error?.status === 429) {
    return "Troppe richieste ravvicinate. Attendi qualche secondo e riprova.";
  }

  return error?.message || "Operazione non disponibile.";
}

async function apiRequest(url, options = {}) {
  const headers = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
    ...(options.headers || {})
  };

  if (csrfToken && options.method && options.method !== "GET") {
    headers["X-CSRF-TOKEN"] = csrfToken;
  }

  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    headers
  });

  const contentType = response.headers.get("content-type") || "";
  const data = contentType.includes("application/json") ? await response.json() : {};

  if (!response.ok) {
    throw { ...data, status: response.status };
  }

  return data;
}

function applyTheme(theme) {
  currentTheme = theme;
  document.documentElement.dataset.theme = theme;
  window.localStorage.setItem("nexum-theme", theme);
  themeToggle?.setAttribute("aria-pressed", String(theme === "dark"));
  themeToggle?.setAttribute(
    "aria-label",
    theme === "dark" ? "Attiva tema chiaro" : "Attiva tema scuro"
  );
}

applyTheme(currentTheme);

themeToggle?.addEventListener("click", () => {
  applyTheme(currentTheme === "dark" ? "light" : "dark");
});

function setView(viewName) {
  if (!views[viewName]) {
    return;
  }

  mainNavItems.forEach((button) => {
    button.classList.toggle("active", button.dataset.view === viewName);
  });

  sections.forEach((section) => {
    section.classList.toggle("active", section.dataset.view === viewName);
  });

  currentView = viewName;
  setText(titleNode, views[viewName]);
}

function activateSubnav(targetId) {
  subNavItems.forEach((button) => {
    button.classList.toggle("active", button.dataset.target === targetId);
  });
}

function goTo(viewName, targetId) {
  setView(viewName);
  activateSubnav(targetId);

  window.requestAnimationFrame(() => {
    document.getElementById(targetId)?.scrollIntoView({ behavior: "smooth", block: "start" });
  });
}

document.querySelectorAll(".nav-item, .nav-subitem").forEach((button) => {
  button.addEventListener("click", () => {
    goTo(button.dataset.view, button.dataset.target);
  });
});

function updateBackToTopVisibility() {
  backToTopButton?.classList.toggle("visible", window.scrollY > 360);
}

window.addEventListener("scroll", updateBackToTopVisibility, { passive: true });
backToTopButton?.addEventListener("click", () => {
  document.getElementById("workspace-top")?.scrollIntoView({ behavior: "smooth", block: "start" });
});
updateBackToTopVisibility();

const promptInput = document.getElementById("prompt-input");
const toneSelect = document.getElementById("tone-select");
const styleSelect = document.getElementById("style-select");
const generateButton = document.getElementById("generate-button");
const generatedTitleInput = document.getElementById("generated-title-input");
const generatedBodyInput = document.getElementById("generated-body-input");
const assistantStatus = document.getElementById("assistant-status");
const assistantComposeNote = document.getElementById("assistant-compose-note");
const assistantResult = document.getElementById("assistant-result");
const metaChars = document.getElementById("meta-chars");
const metaTime = document.getElementById("meta-time");

function updateMeta() {
  const chars = generatedBodyInput?.value.length || 0;
  setText(metaChars, `${chars} caratteri`);
  setText(metaTime, `${Math.max(0, Math.ceil(chars / 500))} min lettura`);
}

generatedBodyInput?.addEventListener("input", updateMeta);
updateMeta();

function setAssistantResultLocked(locked) {
  assistantResult?.classList.toggle("locked", locked);
  assistantResult?.setAttribute("aria-disabled", String(locked));
  assistantResult?.querySelectorAll("input, textarea").forEach((control) => {
    control.disabled = locked;
  });
}

setAssistantResultLocked(true);

function validatePrompt() {
  const prompt = promptInput?.value.trim() || "";
  if (prompt.length < 12) {
    setText(assistantComposeNote, "Aggiungi qualche dettaglio al prompt prima di generare.");
    promptInput?.focus();
    return false;
  }

  return true;
}

async function generateDraft() {
  if (!validatePrompt()) {
    return;
  }

  setText(assistantComposeNote, "Generazione in corso.");
  generateButton.disabled = true;

  try {
    const result = await apiRequest(apiRoutes.communications, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        prompt: promptInput.value.trim(),
        tone: toneSelect?.value || "Chiaro e diretto",
        style: styleSelect?.value || "Testo informativo"
      })
    });

    setValue(generatedTitleInput, result.communication?.title);
    setValue(generatedBodyInput, result.communication?.body);
    setAssistantResultLocked(false);
    updateMeta();
    setText(assistantStatus, result.communication?.status || "Bozza pronta");
    setText(assistantComposeNote, result.message || "Bozza generata.");
    goTo("assistant", "assistant-result");
  } catch (error) {
    setText(assistantComposeNote, humanApiError(error));
  } finally {
    generateButton.disabled = false;
  }
}

generateButton?.addEventListener("click", generateDraft);

const uploadBox = document.getElementById("upload-box");
const documentFileInput = document.getElementById("document-file-input");
const uploadState = document.getElementById("upload-state");
const uploadOutput = document.getElementById("upload-output");
const documentSummaryList = document.getElementById("document-summary-list");
const documentHistory = document.getElementById("document-history");
const documentEmpty = document.getElementById("document-empty");
const copilotDetailSection = document.getElementById("copilot-detail");
const detailTitle = document.getElementById("detail-title");
const detailPreviewTitle = document.getElementById("detail-preview-title");
const detailPreviewMeta = document.getElementById("detail-preview-meta");
const detailPreviewLines = document.getElementById("detail-preview-lines");
const detailPreviewFrame = document.getElementById("detail-preview-frame");
const detailEmployeeInput = document.getElementById("detail-employee-input");
const detailCompanyInput = document.getElementById("detail-company-input");
const detailFileInput = document.getElementById("detail-file-input");
const detailDateInput = document.getElementById("detail-date-input");
const detailPagesInput = document.getElementById("detail-pages-input");
const detailTypeInput = document.getElementById("detail-type-input");
const detailDescriptionInput = document.getElementById("detail-description-input");
const detailConfidenceInput = document.getElementById("detail-confidence-input");
const confidenceReviewThreshold = 80;

function confidenceValue(documentItem) {
  return Number.isFinite(Number(documentItem?.confidence)) ? Number(documentItem.confidence) : null;
}

function documentStatusClass(documentItem) {
  const confidence = confidenceValue(documentItem);
  if (confidence === null || confidence < confidenceReviewThreshold) {
    return "needs-review";
  }

  return "confirmed";
}

function renderDocumentSummary() {
  const withConfidence = documents.filter((item) => confidenceValue(item) !== null).length;
  const needsReview = documents.filter((item) => {
    const confidence = confidenceValue(item);
    return confidence === null || confidence < confidenceReviewThreshold;
  }).length;

  renderMetricList(documentSummaryList, [
    [String(documents.length), "Sotto-documenti"],
    [String(withConfidence), "Campi con confidenza"],
    [String(needsReview), "Da verificare"]
  ]);
}

function renderDocuments() {
  if (!documentHistory) {
    return;
  }

  documentHistory.replaceChildren();
  renderDocumentSummary();
  documentEmpty?.classList.toggle("hidden", documents.length > 0);

  documents.forEach((documentItem) => {
    const row = document.createElement("li");
    row.className = `history-item document-item ${documentStatusClass(documentItem)}`;
    row.classList.toggle("selected", documentItem.id === activeDocumentId);

    const main = document.createElement("div");
    main.className = "history-main";

    const title = document.createElement("strong");
    title.textContent = documentItem.title || documentItem.file || "Documento rilevato";

    const meta = document.createElement("span");
    const confidence = confidenceValue(documentItem);
    meta.textContent = [
      documentItem.employee || "Destinatario non disponibile",
      documentItem.company || "Azienda non disponibile",
      confidence === null ? "Confidenza n/d" : `Confidenza ${confidence}%`
    ].join(" · ");

    const actions = document.createElement("div");
    actions.className = "history-actions";

    const action = document.createElement("button");
    action.className = "text-button";
    action.type = "button";
    action.textContent = "Apri";
    action.addEventListener("click", () => {
      showDocumentDetail(documentItem.id);
    });

    const deleteBtn = document.createElement("button");
    deleteBtn.className = "text-button text-button--danger";
    deleteBtn.type = "button";
    deleteBtn.textContent = "Elimina";
    deleteBtn.addEventListener("click", () => {
      deleteDocument(documentItem.id);
    });

    actions.append(action, deleteBtn);
    main.append(title, meta);
    row.append(main, actions);
    documentHistory.append(row);
  });
}

function renderDocumentPreview(documentItem) {
  if (!detailPreviewFrame) {
    return;
  }

  detailPreviewFrame.replaceChildren();

  if (!documentItem.previewUrl) {
    const fallback = document.createElement("span");
    fallback.textContent = "Preview documento non disponibile.";
    detailPreviewFrame.append(fallback);
    return;
  }

  const iframe = document.createElement("iframe");
  iframe.src = documentItem.previewUrl;
  iframe.title = `Preview ${documentItem.file || "documento"}`;
  iframe.loading = "lazy";
  detailPreviewFrame.append(iframe);
}

function showDocumentDetail(documentId) {
  const documentItem = documents.find((item) => item.id === documentId);
  if (!documentItem) {
    return;
  }

  activeDocumentId = documentId;
  renderDocuments();

  setText(detailTitle, documentItem.title || "Documento rilevato");
  setText(detailPreviewTitle, documentItem.file || "File non disponibile");
  setText(detailPreviewMeta, `${documentItem.pages || "n/d"} pagine · ${documentItem.type || "Tipologia non disponibile"}`);
  setValue(detailEmployeeInput, documentItem.employee || "Non disponibile");
  setValue(detailCompanyInput, documentItem.company || "Non disponibile");
  setValue(detailFileInput, documentItem.file || "Non disponibile");
  setValue(detailDateInput, documentItem.date || "Non disponibile");
  setValue(detailPagesInput, documentItem.pages || "Non disponibile");
  setValue(detailTypeInput, documentItem.type || "Non disponibile");
  setValue(detailDescriptionInput, documentItem.description || "Non disponibile");
  setValue(
    detailConfidenceInput,
    confidenceValue(documentItem) === null ? "Non disponibile" : `${documentItem.confidence}%`
  );

  detailPreviewLines?.replaceChildren();
  (documentItem.previewLines || []).forEach((line) => {
    const item = document.createElement("span");
    item.textContent = line;
    detailPreviewLines?.append(item);
  });
  renderDocumentPreview(documentItem);

  copilotDetailSection?.classList.remove("is-hidden");
  goTo("copilot", "copilot-detail");
}

let activeStream = null;

function closeStream() {
  if (activeStream) {
    activeStream.close();
    activeStream = null;
  }
}

function openDocumentStream(streamUrl) {
  closeStream();

  activeStream = new EventSource(streamUrl);

  activeStream.addEventListener("document", (e) => {
    const doc = JSON.parse(e.data);
    documents = [...documents.filter((d) => d.id !== doc.id), doc];
    renderDocuments();
  });

  activeStream.addEventListener("done", (e) => {
    const data = JSON.parse(e.data);
    applyCopilotState(data.state?.copilot || { documents: [] });
    setText(uploadState, "Analisi completata");
    setText(uploadOutput, "Tutti i sotto-documenti sono stati elaborati.");
    closeStream();
  });

  activeStream.addEventListener("error", (e) => {
    if (e.data) {
      const data = JSON.parse(e.data);
      setText(uploadState, "Analisi non riuscita");
      setText(uploadOutput, data.message || "Errore durante l'elaborazione.");
    }
    closeStream();
  });
}

async function deleteDocument(documentId) {
  try {
    const result = await apiRequest(apiRoutes.documentDelete(documentId), { method: "DELETE" });
    const newState = result.state?.copilot || { documents: [] };

    if (activeDocumentId === documentId) {
      copilotDetailSection?.classList.add("is-hidden");
      activeDocumentId = null;
    }

    applyCopilotState(newState);
  } catch {
    // silent — list stays as-is
  }
}

function applyCopilotState(state, focusDetail = false) {
  documents = state?.documents || [];
  activeDocumentId = documents[0]?.id || null;
  renderDocuments();

  if (activeDocumentId && focusDetail) {
    showDocumentDetail(activeDocumentId);
  }
}

uploadBox?.addEventListener("click", () => {
  documentFileInput?.click();
});

documentFileInput?.addEventListener("change", async () => {
  const file = documentFileInput.files?.[0];
  if (!file) {
    return;
  }

  if (file.type !== "application/pdf") {
    setText(uploadState, "Formato non valido");
    setText(uploadOutput, "Carica un file PDF.");
    documentFileInput.value = "";
    return;
  }

  const formData = new FormData();
  formData.append("document", file);
  setText(uploadState, "Analisi in corso");
  setText(uploadOutput, "Split iniziale e rilevazione campi in esecuzione.");
  uploadBox?.classList.add("processing");

  try {
    const result = await apiRequest(apiRoutes.documentOcr, {
      method: "POST",
      body: formData
    });

    setText(uploadState, "Elaborazione in corso");
    setText(uploadOutput, "I sotto-documenti appariranno man mano che vengono analizzati.");
    goTo("copilot", "copilot-results");
    openDocumentStream(result.streamUrl);
  } catch (error) {
    setText(uploadState, "Caricamento non riuscito");
    setText(uploadOutput, humanApiError(error));
  } finally {
    uploadBox?.classList.remove("processing");
    documentFileInput.value = "";
  }
});

async function bootstrapState() {
  try {
    const state = await apiRequest(apiRoutes.state);
    applyCopilotState(state.copilot || { documents: [] });
  } catch {
    renderDocuments();
  }
}

bootstrapState();
