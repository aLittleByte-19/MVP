import { TestBed } from "@angular/core/testing";
import { of, throwError } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import { DocumentWorkflowService, type DocumentUploadProgress } from "./document-workflow.service";

/**
 * EventSource non esiste in jsdom e comunque non va aperta una connessione
 * vera: questo doppio registra i listener e permette al test di far arrivare
 * gli eventi della pipeline quando vuole.
 */
class FakeEventSource {
  static last: FakeEventSource | null = null;

  readonly listeners = new Map<string, ((event: MessageEvent) => void)[]>();
  closed = false;

  constructor(readonly url: string) {
    FakeEventSource.last = this;
  }

  addEventListener(type: string, handler: (event: MessageEvent) => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), handler]);
  }

  close(): void {
    this.closed = true;
  }

  /** Simula un evento SSE del backend. */
  emit(type: string, data: unknown): void {
    for (const handler of this.listeners.get(type) ?? []) {
      handler(new MessageEvent(type, { data: JSON.stringify(data) }));
    }
  }
}

describe("DocumentWorkflowService", () => {
  let service: DocumentWorkflowService;
  let api: {
    uploadMvpDocument: jest.Mock;
    deleteMvpSubDocument: jest.Mock;
    updateMvpSubDocumentExtractedData: jest.Mock;
    reviewMvpSubDocument: jest.Mock;
    updateMvpSubDocumentSendMessage: jest.Mock;
    listMvpDocuments: jest.Mock;
  };
  let store: { setState: jest.Mock; upsertDocument: jest.Mock; reload: jest.Mock };

  const state = { documents: [], communications: [] };

  beforeEach(() => {
    api = {
      uploadMvpDocument: jest.fn(),
      deleteMvpSubDocument: jest.fn().mockReturnValue(of({ state })),
      updateMvpSubDocumentExtractedData: jest.fn().mockReturnValue(of({ state })),
      reviewMvpSubDocument: jest.fn().mockReturnValue(of({ state })),
      updateMvpSubDocumentSendMessage: jest.fn().mockReturnValue(of({ state })),
      listMvpDocuments: jest.fn()
    };
    store = { setState: jest.fn(), upsertDocument: jest.fn(), reload: jest.fn() };

    TestBed.configureTestingModule({
      providers: [
        { provide: AlittlebyteMVPAPIService, useValue: api },
        { provide: MvpStateStore, useValue: store }
      ]
    });
    service = TestBed.inject(DocumentWorkflowService);

    FakeEventSource.last = null;
    (globalThis as { EventSource?: unknown }).EventSource = FakeEventSource;
  });

  describe("upload", () => {
    const file = new File(["contenuto"], "cedolini.pdf", { type: "application/pdf" });

    /** Avvia l'upload e raccoglie le emissioni fino alla chiusura. */
    function startUpload(): { emissions: DocumentUploadProgress[]; done: boolean; error?: unknown } {
      const result: { emissions: DocumentUploadProgress[]; done: boolean; error?: unknown } = {
        emissions: [],
        done: false
      };

      service.upload(file).subscribe({
        next: (progress) => result.emissions.push(progress),
        error: (error: unknown) => (result.error = error),
        complete: () => (result.done = true)
      });

      return result;
    }

    beforeEach(() => {
      api.uploadMvpDocument.mockReturnValue(
        of({ message: "Documento ricevuto.", streamUrl: "/api/v1/documents/1/stream" })
      );
    });

    it("conferma l'upload e apre lo stream sull'URL indicato dal backend", () => {
      const result = startUpload();

      expect(api.uploadMvpDocument).toHaveBeenCalledWith({ document: file });
      expect(result.emissions[0]).toEqual({ status: "Documento ricevuto.", phase: "queued" });
      expect(FakeEventSource.last?.url).toBe("/api/v1/documents/1/stream");
    });

    it("inoltra i metadati manuali insieme al file", () => {
      const emissions: DocumentUploadProgress[] = [];

      service
        .upload(file, {
          documentType: "cedolino",
          companyName: "Acme Srl",
          month: 3,
          year: 2026
        })
        .subscribe((progress) => emissions.push(progress));

      expect(api.uploadMvpDocument).toHaveBeenCalledWith({
        document: file,
        documentType: "cedolino",
        companyName: "Acme Srl",
        month: 3,
        year: 2026
      });
      expect(emissions[0]?.phase).toBe("queued");
    });

    it.each([
      ["pending", 0, "queued", "Documento in coda di elaborazione."],
      ["processing", 0, "processing", "Analisi OCR del documento in corso."],
      ["processing", 3, "extracting", "Estrazione dati dai sotto-documenti in corso."],
      ["completed", 3, "completed", "Elaborazione completata."],
      ["failed", 0, "failed", "Elaborazione non disponibile. Controlla lo stato del documento."]
    ])("traduce lo stato %s con %i sotto-documenti nella fase %s", (status, subDocuments, phase, label) => {
      const result = startUpload();

      FakeEventSource.last?.emit("progress", { status, subDocuments });

      expect(result.emissions.at(-1)).toEqual({ status: label, phase });
    });

    it("inserisce nello store ogni sotto-documento che arriva dallo stream", () => {
      const result = startUpload();
      const subDocument = { id: "sub-7", employeeName: "Mario Rossi" };

      FakeEventSource.last?.emit("document", subDocument);

      expect(store.upsertDocument).toHaveBeenCalledWith(subDocument);
      expect(result.emissions.at(-1)).toEqual({
        status: "Estrazione dati dai sotto-documenti in corso.",
        phase: "extracting",
        receivedDocumentId: "sub-7"
      });
    });

    it("alla fine adotta lo stato autorevole, chiude lo stream e completa", () => {
      const result = startUpload();

      FakeEventSource.last?.emit("done", { state });

      expect(store.setState).toHaveBeenCalledWith(state);
      expect(result.emissions.at(-1)).toEqual({ status: "Elaborazione completata.", phase: "completed" });
      expect(FakeEventSource.last?.closed).toBe(true);
      expect(result.done).toBe(true);
    });

    it("completa anche se l'evento done non porta con se' lo stato", () => {
      const result = startUpload();

      FakeEventSource.last?.emit("done", {});

      expect(store.setState).not.toHaveBeenCalled();
      expect(result.done).toBe(true);
    });

    it("su errore dello stream ricarica lo stato invece di inventarne uno", () => {
      // Nessun fallback automatico: si va a chiedere al backend com'e' andata.
      const result = startUpload();

      FakeEventSource.last?.emit("error", {});

      expect(result.emissions.at(-1)).toEqual({
        status: "Elaborazione non disponibile. Controlla lo stato del documento.",
        phase: "failed"
      });
      expect(store.reload).toHaveBeenCalled();
      expect(FakeEventSource.last?.closed).toBe(true);
      expect(result.done).toBe(true);
    });

    it("propaga l'errore quando l'upload stesso fallisce", () => {
      const failure = new Error("413 payload troppo grande");
      api.uploadMvpDocument.mockReturnValue(throwError(() => failure));

      const result = startUpload();

      expect(result.error).toBe(failure);
      expect(FakeEventSource.last).toBeNull();
    });

    it("chiude lo stream quando il chiamante annulla la sottoscrizione", () => {
      // Senza questo, cambiare pagina durante l'elaborazione lascerebbe
      // aperta una connessione SSE per ogni upload avviato.
      const subscription = service.upload(file).subscribe();

      subscription.unsubscribe();

      expect(FakeEventSource.last?.closed).toBe(true);
    });
  });

  describe("mutazioni sul sotto-documento", () => {
    it("elimina usando l'id numerico e adotta lo stato restituito", () => {
      service.deleteSubDocument("sub-42").subscribe();

      expect(api.deleteMvpSubDocument).toHaveBeenCalledWith(42);
      expect(store.setState).toHaveBeenCalledWith(state);
    });

    it("salva i dati estratti", () => {
      const payload = { employeeName: "Mario Rossi", fiscalCode: "RSSMRA85M01H501Q" };

      service.saveExtractedData("sub-42", payload).subscribe();

      expect(api.updateMvpSubDocumentExtractedData).toHaveBeenCalledWith(42, payload);
      expect(store.setState).toHaveBeenCalledWith(state);
    });

    it("segna il documento come revisionato", () => {
      service.markReviewed("sub-9").subscribe();

      expect(api.reviewMvpSubDocument).toHaveBeenCalledWith(9);
      expect(store.setState).toHaveBeenCalledWith(state);
    });

    it("salva il messaggio di accompagnamento", () => {
      const payload = {
        recipient: "mario.rossi@example.com",
        subject: "Cedolino di marzo",
        body: "In allegato il cedolino."
      };

      service.saveSendMessage("sub-9", payload).subscribe();

      expect(api.updateMvpSubDocumentSendMessage).toHaveBeenCalledWith(9, payload);
      expect(store.setState).toHaveBeenCalledWith(state);
    });
  });

  describe("searchDocuments", () => {
    it("inoltra tutti i criteri al backend e restituisce i soli elementi", () => {
      const items = [{ id: "sub-1" }];
      api.listMvpDocuments.mockReturnValue(of({ items }));
      const filters = {
        search: "Rossi",
        sendStatus: "sent" as const,
        confidenceThreshold: 80,
        confidenceCriterion: "below" as const,
        month: 3,
        year: 2026
      };

      let received: unknown;
      service.searchDocuments(filters).subscribe((result) => (received = result));

      expect(api.listMvpDocuments).toHaveBeenCalledWith(filters);
      expect(received).toBe(items);
    });

    it("chiede comunque la lista quando non ci sono filtri", () => {
      api.listMvpDocuments.mockReturnValue(of({ items: [] }));

      service.searchDocuments({}).subscribe();

      expect(api.listMvpDocuments).toHaveBeenCalledWith({
        search: undefined,
        sendStatus: undefined,
        confidenceThreshold: undefined,
        confidenceCriterion: undefined,
        month: undefined,
        year: undefined
      });
    });
  });

  describe("previewStatus", () => {
    /** Risposta minima con lo stretto necessario per il servizio. */
    function fetchResolving(init: { ok: boolean; status: number; contentType?: string }): jest.Mock {
      return jest.fn().mockResolvedValue({
        ok: init.ok,
        status: init.status,
        headers: { get: () => init.contentType ?? null }
      });
    }

    async function statusesFor(fetchMock: jest.Mock): Promise<string[]> {
      (globalThis as { fetch?: unknown }).fetch = fetchMock;
      const seen: string[] = [];

      await new Promise<void>((resolve) => {
        service.previewStatus("/api/v1/documents/1/preview").subscribe({
          next: (status) => seen.push(status),
          complete: () => resolve()
        });
      });

      return seen;
    }

    it("segnala l'anteprima disponibile solo se arriva davvero un PDF", async () => {
      const statuses = await statusesFor(
        fetchResolving({ ok: true, status: 200, contentType: "application/pdf" })
      );

      expect(statuses).toEqual(["loading", "available"]);
    });

    it("considera non disponibile una risposta ok che non e' un PDF", async () => {
      const statuses = await statusesFor(
        fetchResolving({ ok: true, status: 200, contentType: "text/html" })
      );

      expect(statuses).toEqual(["loading", "unavailable"]);
    });

    it("considera non disponibile l'anteprima assente", async () => {
      expect(await statusesFor(fetchResolving({ ok: false, status: 404 }))).toEqual([
        "loading",
        "unavailable"
      ]);
    });

    it("distingue lo storage irraggiungibile dall'anteprima mancante", async () => {
      // 503 vuol dire che il documento potrebbe esserci: e' un problema di
      // storage, e all'utente va detta una cosa diversa da "non c'e'".
      expect(await statusesFor(fetchResolving({ ok: false, status: 503 }))).toEqual([
        "loading",
        "unreachable"
      ]);
    });

    it("tratta come irraggiungibile anche la rete caduta", async () => {
      const statuses = await statusesFor(jest.fn().mockRejectedValue(new Error("network down")));

      expect(statuses).toEqual(["loading", "unreachable"]);
    });

    it("non emette piu' nulla dopo l'annullamento della sottoscrizione", async () => {
      let resolveFetch: (value: unknown) => void = () => undefined;
      (globalThis as { fetch?: unknown }).fetch = jest
        .fn()
        .mockReturnValue(new Promise((resolve) => (resolveFetch = resolve)));

      const seen: string[] = [];
      const subscription = service
        .previewStatus("/api/v1/documents/1/preview")
        .subscribe((status) => seen.push(status));

      subscription.unsubscribe();
      resolveFetch({ ok: true, status: 200, headers: { get: () => "application/pdf" } });
      await Promise.resolve();

      expect(seen).toEqual(["loading"]);
    });
  });
});
