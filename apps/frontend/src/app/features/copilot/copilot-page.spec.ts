import { signal } from "@angular/core";
import { TestBed, fakeAsync, tick } from "@angular/core/testing";
import { of, throwError } from "rxjs";
import type { SubDocument } from "../../../api/generated/model";
import { MvpStateStore } from "../../core/state/mvp-state.store";
import { CopilotPage } from "./copilot-page";
import { CopilotPageViewModel } from "./copilot-page.view-model";
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

/**
 * Test della View (Component Angular): copre solo il collante col template
 * e il cablaggio che richiede un injection context (effect(),
 * takeUntilDestroyed()) — non richiede TestBed per costruire il
 * ViewModel. La logica di dominio (upload, revisione, eliminazione...) è
 * testata senza Angular in copilot-page.view-model.spec.ts.
 */
describe("CopilotPage", () => {
  let documents: ReturnType<typeof signal<SubDocument[]>>;
  let error: ReturnType<typeof signal<string | null>>;
  let workflow: Record<string, jest.Mock>;
  let reload: jest.Mock;

  beforeEach(() => {
    documents = signal<SubDocument[]>([]);
    error = signal<string | null>(null);
    reload = jest.fn();
    workflow = {
      searchDocuments: jest.fn(() => of({ items: [], total: 0, page: 1, perPage: 10 })),
      upload: jest.fn(),
      deleteSubDocument: jest.fn(),
      markReviewed: jest.fn(),
      saveExtractedData: jest.fn(),
      saveSendMessage: jest.fn(),
      previewStatus: jest.fn(() => of("idle"))
    };
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MvpStateStore,
          useValue: {
            documents,
            reload,
            error,
            loading: signal(false),
            copilotMetrics: signal([]),
            metricEntry: () => null
          }
        },
        { provide: DocumentWorkflowService, useValue: workflow }
      ]
    });
  });

  function createPage(): CopilotPage {
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();
    return fixture.componentInstance;
  }

  it("costruisce il ViewModel e delega ad esso tutto lo stato applicativo", () => {
    const page = createPage();

    expect(page["vm"]).toBeInstanceOf(CopilotPageViewModel);
  });

  it("carica i risultati filtrati tramite l'effect e li rende nel template", () => {
    const first = subDocument("sub-1");
    const second = subDocument("sub-2");
    workflow["searchDocuments"].mockReturnValue(of({ items: [first, second], total: 2, page: 1, perPage: 10 }));
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["vm"].filteredDocuments()).toEqual([first, second]);
    expect(fixture.nativeElement.textContent).toContain("2 record");
  });

  it("richiede tramite l'effect lo stato di anteprima del documento selezionato", () => {
    const selected: SubDocument = { ...subDocument("sub-1"), previewUrl: "/api/v1/documents/sub-1/preview" };
    workflow["searchDocuments"].mockReturnValue(of({ items: [selected], total: 1, page: 1, perPage: 10 }));
    workflow["previewStatus"].mockReturnValue(of("available"));
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();

    expect(workflow["previewStatus"]).toHaveBeenCalledWith("/api/v1/documents/sub-1/preview");
    expect(fixture.componentInstance["vm"].previewStatus()).toBe("available");
  });

  it("propaga l'errore di ricerca dell'effect al ViewModel", () => {
    workflow["searchDocuments"].mockReturnValue(throwError(() => new Error("ricerca fallita")));
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();

    expect(fixture.componentInstance["vm"].documentsError()).toBe("ricerca fallita");
    expect(fixture.nativeElement.textContent).toContain("ricerca fallita");
  });

  it("rende il banner d'errore dello store", () => {
    error.set("Backend non disponibile");
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain("Backend non disponibile");
  });

  it("propaga il form dei filtri, con debounce, al ViewModel", fakeAsync(() => {
    const fixture = TestBed.createComponent(CopilotPage);
    fixture.detectChanges();
    const page = fixture.componentInstance;
    workflow["searchDocuments"].mockClear();

    page["filterForm"].controls.search.setValue("rossi");
    tick(300);

    expect(page["vm"].activeFilters()).toEqual({ search: "rossi" });
  }));

  it("azzera i filtri del form", () => {
    const page = createPage();
    page["filterForm"].controls.search.setValue("rossi");

    page["resetFilters"]();

    expect(page["filterForm"].controls.search.value).toBe("");
  });
});
