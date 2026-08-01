import { signal } from "@angular/core";
import { TestBed } from "@angular/core/testing";
import { of, throwError } from "rxjs";
import type { SubDocument, UpdateExtractedDataRequest, UpdateSendMessageRequest } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { CopilotPage } from "./copilot-page";
import { DocumentWorkflowService } from "./data/document-workflow.service";

function subDocument(id: string): SubDocument {
  return {
    id,
    employee: "Mario Rossi",
    companyName: "Acme SpA",
    title: "Cedolino",
    file: "cedolino.pdf",
    confidence: 90,
    reviewStatus: "auto_validated",
    reviewStatusLabel: "Validato",
    sendStatus: "pending",
    sendStatusLabel: "Da inviare",
    previewLines: []
  };
}

describe("CopilotPage", () => {
  let documents: ReturnType<typeof signal<SubDocument[]>>;
  let workflow: Record<string, jest.Mock>;
  let reload: jest.Mock;

  beforeEach(() => {
    documents = signal<SubDocument[]>([]);
    reload = jest.fn();
    workflow = {
      searchDocuments: jest.fn(() => of([])),
      upload: jest.fn(),
      deleteSubDocument: jest.fn(),
      markReviewed: jest.fn(),
      saveExtractedData: jest.fn(),
      saveSendMessage: jest.fn()
    };
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MvpStateStore,
          useValue: { documents, reload, error: signal(null), loading: signal(false), copilotMetrics: signal([]) }
        },
        { provide: DocumentWorkflowService, useValue: workflow }
      ]
    });
    TestBed.overrideComponent(CopilotPage, { set: { template: "", imports: [] } });
  });

  function createPage(): CopilotPage {
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();
    return fixture.componentInstance;
  }

  it("carica i risultati filtrati e risolve selezione e fallback sulla lista visibile", () => {
    const first = subDocument("sub-1");
    const second = subDocument("sub-2");
    workflow["searchDocuments"].mockReturnValue(of([first, second]));
    const page = createPage();

    expect(page["filteredDocuments"]()).toEqual([first, second]);
    expect(page["selectedDocument"]()).toBe(first);
    expect(page["selectedDocumentIdForList"]()).toBe("sub-1");

    page["selectDocument"]("sub-2");
    expect(page["selectedDocument"]()).toBe(second);

    page["selectDocument"]("non-visibile");
    expect(page["selectedDocument"]()).toBe(first);
  });

  it("espone l'errore dello storico e gestisce lista vuota", () => {
    workflow["searchDocuments"].mockReturnValue(throwError(() => new Error("ricerca fallita")));
    const page = createPage();

    expect(page["documentsError"]()).toBe("ricerca fallita");
    expect(page["selectedDocument"]()).toBeNull();
    expect(page["selectedDocumentIdForList"]()).toBeNull();
  });

  it("azzera i filtri e riflette la loro presenza", () => {
    const page = createPage();
    page["activeFilters"].set({ search: "rossi" });
    page["filterForm"].controls.search.setValue("rossi");
    expect(page["hasActiveFilters"]()).toBe(true);

    page["resetFilters"]();

    expect(page["filterForm"].controls.search.value).toBe("");
  });

  it("segue l'upload e seleziona il documento ricevuto", () => {
    const file = new File(["pdf"], "cedolino.pdf", { type: "application/pdf" });
    workflow["upload"].mockReturnValue(of(
      { status: "Caricato", phase: "processing" },
      { status: "Analizzato", phase: "completed", receivedDocumentId: "sub-7" }
    ));
    const page = createPage();

    page["upload"](file);

    expect(workflow["upload"]).toHaveBeenCalledWith(file);
    expect(page["uploadStatus"]()).toBe("Analizzato");
    expect(page["uploadPhase"]()).toBe("completed");
    expect(page["selectedDocumentId"]()).toBe("sub-7");
    expect(page["isUploading"]()).toBe(false);
  });

  it("gestisce l'errore upload e ricarica lo stato autorevole", () => {
    workflow["upload"].mockReturnValue(throwError(() => new Error("upload fallito")));
    const page = createPage();

    page["upload"](new File(["pdf"], "cedolino.pdf"));

    expect(page["uploadPhase"]()).toBe("failed");
    expect(page["uploadStatus"]()).toBe("upload fallito");
    expect(page["isUploading"]()).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("elimina un documento e pulisce la selezione", () => {
    workflow["deleteSubDocument"].mockReturnValue(of({}));
    const page = createPage();
    page["selectedDocumentId"].set("sub-7");

    page["deleteDocument"]("sub-7");

    expect(page["selectedDocumentId"]()).toBeNull();
    expect(page["isDeleting"]()).toBe(false);
  });

  it("espone l'errore di eliminazione", () => {
    workflow["deleteSubDocument"].mockReturnValue(throwError(() => new Error("delete fallita")));
    const page = createPage();

    page["deleteDocument"]("sub-7");

    expect(page["reviewError"]()).toBe("delete fallita");
    expect(page["isDeleting"]()).toBe(false);
  });

  it("marca il documento come revisionato oppure espone l'errore", () => {
    const page = createPage();
    workflow["markReviewed"].mockReturnValue(of({}));
    page["markReviewed"]("sub-7");
    expect(page["selectedDocumentId"]()).toBe("sub-7");
    expect(page["isSavingReview"]()).toBe(false);

    workflow["markReviewed"].mockReturnValue(throwError(() => new Error("validazione fallita")));
    page["markReviewed"]("sub-8");
    expect(page["reviewError"]()).toBe("validazione fallita");
    expect(page["isSavingReview"]()).toBe(false);
  });

  it("salva i dati estratti e mantiene selezionato il documento", () => {
    const payload = { employee: "Mario Rossi" } as unknown as UpdateExtractedDataRequest;
    workflow["saveExtractedData"].mockReturnValue(of({}));
    const page = createPage();

    page["saveReview"]({ documentId: "sub-7", payload });

    expect(workflow["saveExtractedData"]).toHaveBeenCalledWith("sub-7", payload);
    expect(page["selectedDocumentId"]()).toBe("sub-7");
    expect(page["reviewError"]()).toBeNull();
    expect(page["reviewFieldErrors"]()).toBeNull();
  });

  it("espone gli errori nel salvataggio dei dati estratti", () => {
    workflow["saveExtractedData"].mockReturnValue(throwError(() => new Error("revisione fallita")));
    const page = createPage();

    page["saveReview"]({ documentId: "sub-7", payload: {} as UpdateExtractedDataRequest });

    expect(page["reviewError"]()).toBe("revisione fallita");
    expect(page["reviewFieldErrors"]()).toBeNull();
    expect(page["isSavingReview"]()).toBe(false);
  });

  it("salva il messaggio di invio oppure espone l'errore", () => {
    const payload = { recipient: "mario@example.test" } as unknown as UpdateSendMessageRequest;
    const page = createPage();
    workflow["saveSendMessage"].mockReturnValue(of({}));

    page["saveSendMessage"]({ documentId: "sub-7", payload });
    expect(workflow["saveSendMessage"]).toHaveBeenCalledWith("sub-7", payload);
    expect(page["selectedDocumentId"]()).toBe("sub-7");
    expect(page["isSavingSendMessage"]()).toBe(false);

    workflow["saveSendMessage"].mockReturnValue(throwError(() => new Error("messaggio fallito")));
    page["saveSendMessage"]({ documentId: "sub-7", payload });
    expect(page["sendMessageError"]()).toBe("messaggio fallito");
    expect(page["isSavingSendMessage"]()).toBe(false);
  });
});
