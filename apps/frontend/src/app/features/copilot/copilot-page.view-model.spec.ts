import { signal } from "@angular/core";
import { of, throwError } from "rxjs";
import type { SubDocument, UpdateExtractedDataRequest, UpdateSendMessageRequest } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { CopilotPageViewModel } from "./copilot-page.view-model";
import type { DocumentWorkflowService } from "./data/document-workflow.service";

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

/**
 * Test di dominio puro sul ViewModel (nessun bootstrap Angular/TestBed):
 * CopilotPageViewModel si costruisce con `new`, come qualunque classe
 * TypeScript — prova diretta che qui MVVM non è solo nominale.
 */
describe("CopilotPageViewModel", () => {
  let workflow: Record<string, jest.Mock>;
  let reload: jest.Mock;
  let error: ReturnType<typeof signal<string | null>>;
  let loading: ReturnType<typeof signal<boolean>>;
  let copilotMetrics: ReturnType<typeof signal<unknown[]>>;

  beforeEach(() => {
    reload = jest.fn();
    error = signal<string | null>(null);
    loading = signal(false);
    copilotMetrics = signal<unknown[]>([]);
    workflow = {
      searchDocuments: jest.fn(() => of([])),
      upload: jest.fn(),
      deleteSubDocument: jest.fn(),
      markReviewed: jest.fn(),
      saveExtractedData: jest.fn(),
      saveSendMessage: jest.fn()
    };
  });

  function createViewModel(): CopilotPageViewModel {
    const store = { reload, error, loading, copilotMetrics } as unknown as MvpStateStore;
    return new CopilotPageViewModel(workflow as unknown as DocumentWorkflowService, store);
  }

  it("espone error/loading/metrics come pass-through dello store, senza che la View lo legga direttamente", () => {
    const vm = createViewModel();

    expect(vm.error()).toBeNull();
    expect(vm.loading()).toBe(false);
    expect(vm.metrics()).toEqual([]);

    error.set("errore autorevole");
    loading.set(true);
    expect(vm.error()).toBe("errore autorevole");
    expect(vm.loading()).toBe(true);
  });

  it("reload cerca con i filtri attivi e popola la lista filtrata", () => {
    const first = subDocument("sub-1");
    const second = subDocument("sub-2");
    workflow["searchDocuments"].mockReturnValue(of([first, second]));
    const vm = createViewModel();
    vm.setActiveFilters({ search: "rossi" });

    vm.reload();

    expect(workflow["searchDocuments"]).toHaveBeenCalledWith({ search: "rossi" });
    expect(vm.selectedDocument()).toBe(first);
    expect(vm.selectedDocumentIdForList()).toBe("sub-1");

    vm.selectDocument("sub-2");
    expect(vm.selectedDocument()).toBe(second);

    vm.selectDocument("non-visibile");
    expect(vm.selectedDocument()).toBe(first);
  });

  it("reload espone l'errore dello storico e gestisce lista vuota", () => {
    workflow["searchDocuments"].mockReturnValue(throwError(() => new Error("ricerca fallita")));
    const vm = createViewModel();

    vm.reload();

    expect(vm.documentsError()).toBe("ricerca fallita");
    expect(vm.selectedDocument()).toBeNull();
    expect(vm.selectedDocumentIdForList()).toBeNull();
  });

  it("riflette la presenza di filtri attivi", () => {
    const vm = createViewModel();

    expect(vm.hasActiveFilters()).toBe(false);

    vm.setActiveFilters({ search: "rossi" });
    expect(vm.hasActiveFilters()).toBe(true);

    vm.setActiveFilters({});
    expect(vm.hasActiveFilters()).toBe(false);
  });

  it("segue l'upload e seleziona il documento ricevuto", () => {
    const file = new File(["pdf"], "cedolino.pdf", { type: "application/pdf" });
    const metadata = { documentType: "cedolino" as const, companyName: "Acme Srl", month: 3, year: 2026 };
    workflow["upload"].mockReturnValue(
      of(
        { status: "Caricato", phase: "processing" },
        { status: "Analizzato", phase: "completed", receivedDocumentId: "sub-7" }
      )
    );
    const vm = createViewModel();

    vm.upload({ file, metadata });

    expect(workflow["upload"]).toHaveBeenCalledWith(file, metadata);
    expect(vm.uploadStatus()).toBe("Analizzato");
    expect(vm.uploadPhase()).toBe("completed");
    expect(vm.selectedDocumentId()).toBe("sub-7");
    expect(vm.isUploading()).toBe(false);
  });

  it("gestisce l'errore upload e ricarica lo stato autorevole", () => {
    workflow["upload"].mockReturnValue(throwError(() => new Error("upload fallito")));
    const vm = createViewModel();

    vm.upload({ file: new File(["pdf"], "cedolino.pdf"), metadata: {} });

    expect(vm.uploadPhase()).toBe("failed");
    expect(vm.uploadStatus()).toBe("upload fallito");
    expect(vm.isUploading()).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("elimina un documento e pulisce la selezione", () => {
    workflow["deleteSubDocument"].mockReturnValue(of({}));
    const vm = createViewModel();
    vm.selectedDocumentId.set("sub-7");

    vm.deleteDocument("sub-7");

    expect(vm.selectedDocumentId()).toBeNull();
    expect(vm.isDeleting()).toBe(false);
  });

  it("espone l'errore di eliminazione", () => {
    workflow["deleteSubDocument"].mockReturnValue(throwError(() => new Error("delete fallita")));
    const vm = createViewModel();

    vm.deleteDocument("sub-7");

    expect(vm.reviewError()).toBe("delete fallita");
    expect(vm.isDeleting()).toBe(false);
  });

  it("marca il documento come revisionato oppure espone l'errore", () => {
    const vm = createViewModel();
    workflow["markReviewed"].mockReturnValue(of({}));
    vm.markReviewed("sub-7");
    expect(vm.selectedDocumentId()).toBe("sub-7");
    expect(vm.isSavingReview()).toBe(false);

    workflow["markReviewed"].mockReturnValue(throwError(() => new Error("validazione fallita")));
    vm.markReviewed("sub-8");
    expect(vm.reviewError()).toBe("validazione fallita");
    expect(vm.isSavingReview()).toBe(false);
  });

  it("salva i dati estratti e mantiene selezionato il documento", () => {
    const payload = { employee: "Mario Rossi" } as unknown as UpdateExtractedDataRequest;
    workflow["saveExtractedData"].mockReturnValue(of({}));
    const vm = createViewModel();

    vm.saveReview({ documentId: "sub-7", payload });

    expect(workflow["saveExtractedData"]).toHaveBeenCalledWith("sub-7", payload);
    expect(vm.selectedDocumentId()).toBe("sub-7");
    expect(vm.reviewError()).toBeNull();
    expect(vm.reviewFieldErrors()).toBeNull();
  });

  it("espone gli errori nel salvataggio dei dati estratti", () => {
    workflow["saveExtractedData"].mockReturnValue(throwError(() => new Error("revisione fallita")));
    const vm = createViewModel();

    vm.saveReview({ documentId: "sub-7", payload: {} as UpdateExtractedDataRequest });

    expect(vm.reviewError()).toBe("revisione fallita");
    expect(vm.reviewFieldErrors()).toBeNull();
    expect(vm.isSavingReview()).toBe(false);
  });

  it("salva il messaggio di invio oppure espone l'errore", () => {
    const payload = { recipient: "mario@example.test" } as unknown as UpdateSendMessageRequest;
    const vm = createViewModel();
    workflow["saveSendMessage"].mockReturnValue(of({}));

    vm.saveSendMessage({ documentId: "sub-7", payload });
    expect(workflow["saveSendMessage"]).toHaveBeenCalledWith("sub-7", payload);
    expect(vm.selectedDocumentId()).toBe("sub-7");
    expect(vm.isSavingSendMessage()).toBe(false);

    workflow["saveSendMessage"].mockReturnValue(throwError(() => new Error("messaggio fallito")));
    vm.saveSendMessage({ documentId: "sub-7", payload });
    expect(vm.sendMessageError()).toBe("messaggio fallito");
    expect(vm.isSavingSendMessage()).toBe(false);
  });
});
