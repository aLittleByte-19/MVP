import { Injector } from "@angular/core";
import { Observable, of, throwError } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import { SseClient, type SseHandlers } from "../../../core/http/sse-client";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import { AssistantService } from "./assistant.service";
import type { CommunicationGenerationProgress } from "../assistant.model";

class FakeSseClient {
  static last: FakeSseClient | null = null;

  url = "";
  handlers: SseHandlers | null = null;
  closed = false;

  connect(url: string, handlers: SseHandlers): () => void {
    this.url = url;
    this.handlers = handlers;
    FakeSseClient.last = this;

    return () => {
      this.closed = true;
    };
  }

  emit(event: string, data: unknown): void {
    this.handlers?.onEvent(event, data);
  }

  emitNamedError(message: string): void {
    this.handlers?.onNamedError(message);
  }

  emitConnectionError(): void {
    this.handlers?.onConnectionError();
  }
}

describe("AssistantService", () => {
  let service: AssistantService;
  let setState: jest.Mock;
  let reload: jest.Mock;
  let api: Record<string, jest.Mock>;

  beforeEach(() => {
    FakeSseClient.last = null;
    setState = jest.fn();
    reload = jest.fn();

    api = {
      generateMvpCommunication: jest.fn(() =>
        of({ message: "Generazione avviata.", communicationId: 7, streamUrl: "/api/v1/communications/7/stream" })
      ),
      regenerateMvpCommunication: jest.fn(() =>
        of({ message: "Rigenerazione avviata.", communicationId: 7, streamUrl: "/api/v1/communications/7/stream" })
      ),
      updateMvpCommunicationCoverImage: jest.fn(),
      removeMvpCommunicationCoverImage: jest.fn(),
      discardMvpCommunication: jest.fn(),
      saveMvpCommunication: jest.fn(),
      saveMvpPromptConfiguration: jest.fn(),
      deleteMvpPromptConfiguration: jest.fn(),
      deleteMvpCommunication: jest.fn(),
      rateMvpCommunication: jest.fn(),
      updateMvpCommunication: jest.fn(),
      listMvpCommunications: jest.fn(),
      favoriteMvpCommunication: jest.fn(),
      unfavoriteMvpCommunication: jest.fn()
    };

    const injector = Injector.create({
      providers: [
        { provide: AlittlebyteMVPAPIService, useValue: api },
        { provide: MvpStateStore, useValue: { setState, reload } },
        { provide: SseClient, useClass: FakeSseClient },
        { provide: AssistantService, useClass: AssistantService, deps: [] }
      ]
    });

    service = injector.get(AssistantService);
  });

  it("emits the generation phases and completes on done", () => {
    const emissions: CommunicationGenerationProgress[] = [];

    service
      .generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe((progress) => emissions.push(progress));

    const stream = FakeSseClient.last!;
    stream.emit("progress", { generationStatus: "processing", coverStatus: "pending" });
    stream.emit("text", { title: "Titolo", body: "Corpo" });
    stream.emit("cover", { coverImageUrl: "/api/v1/communications/7/cover-image", coverStatus: "ready", coverError: null });
    stream.emit("done", { communication: { id: 7 }, state: { assistant: {}, copilot: {} } });

    expect(emissions.map((emission) => emission.phase)).toEqual([
      "queued",
      "generating-text",
      "generating-cover",
      "generating-cover",
      "completed"
    ]);
    expect(emissions[2].text).toEqual({ title: "Titolo", body: "Corpo" });
    expect(setState).toHaveBeenCalledTimes(1);
    expect(stream.closed).toBe(true);
  });

  it("keeps the generation successful when only the cover degrades", () => {
    const emissions: CommunicationGenerationProgress[] = [];

    service
      .generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe((progress) => emissions.push(progress));

    const stream = FakeSseClient.last!;
    stream.emit("text", { title: "Titolo", body: "Corpo" });
    stream.emit("cover", { coverImageUrl: null, coverStatus: "failed", coverError: "Copertina non disponibile." });
    stream.emit("done", { communication: { id: 7 }, state: { assistant: {}, copilot: {} } });

    const coverEmission = emissions.find((emission) => emission.cover);

    expect(coverEmission?.cover?.coverStatus).toBe("failed");
    expect(coverEmission?.phase).not.toBe("failed");
    expect(emissions[emissions.length - 1].phase).toBe("completed");
  });

  it("regenerate follows the same progress and completion flow as generate", () => {
    const emissions: CommunicationGenerationProgress[] = [];

    service.regenerate(7).subscribe((progress) => emissions.push(progress));

    const stream = FakeSseClient.last!;
    stream.emit("text", { title: "Titolo nuovo", body: "Corpo nuovo" });
    stream.emit("done", { communication: { id: 7 }, state: { assistant: {}, copilot: {} } });

    expect(emissions[0].status).toBe("Rigenerazione avviata.");
    expect(emissions[emissions.length - 1].phase).toBe("completed");
    expect(setState).toHaveBeenCalledTimes(1);
    expect(stream.closed).toBe(true);
  });

  it.each([
    [{ generationStatus: "pending", coverStatus: "pending" }, "generating-text", "Generazione in coda."],
    [{ generationStatus: "processing", coverStatus: "processing" }, "generating-cover", "Generazione copertina in corso."],
    [{ generationStatus: "completed", coverStatus: "ready" }, "completed", "Bozza generata correttamente."],
    [{ generationStatus: "failed", coverStatus: "failed" }, "failed", "Generazione non disponibile."]
  ])("mappa ogni stato di avanzamento SSE", (event, phase, status) => {
    const emissions: CommunicationGenerationProgress[] = [];
    service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe((progress) => emissions.push(progress));

    FakeSseClient.last!.emit("progress", event);

    expect(emissions.at(-1)).toMatchObject({ phase, status, communicationId: 7 });
  });

  it("completa con errore controllato quando lo stream emette un errore nominato", () => {
    const emissions: CommunicationGenerationProgress[] = [];
    let completed = false;
    service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe({ next: (progress) => emissions.push(progress), complete: () => { completed = true; } });

    const stream = FakeSseClient.last!;
    stream.emitNamedError("Generazione non disponibile.");

    expect(emissions.at(-1)?.phase).toBe("failed");
    expect(reload).toHaveBeenCalledTimes(1);
    expect(stream.closed).toBe(true);
    expect(completed).toBe(true);
  });

  it("su caduta della connessione ricarica lo stato senza marcare la pipeline come fallita", () => {
    const emissions: CommunicationGenerationProgress[] = [];
    service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe((progress) => emissions.push(progress));

    FakeSseClient.last!.emitConnectionError();

    expect(emissions.at(-1)?.phase).toBe("queued");
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("segnala still_running senza chiudere lo stream", () => {
    const emissions: CommunicationGenerationProgress[] = [];
    service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe((progress) => emissions.push(progress));

    FakeSseClient.last!.emit("still_running", { message: "Generazione ancora in corso. Lo stato verrà aggiornato." });

    expect(emissions.at(-1)).toMatchObject({ phase: "still_running" });
    expect(FakeSseClient.last!.closed).toBe(false);
  });

  it("propaga un errore della richiesta iniziale senza aprire lo stream", () => {
    const error = new Error("richiesta rifiutata");
    api["generateMvpCommunication"].mockReturnValue(throwError(() => error));
    let received: unknown;

    service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe({ error: (value: unknown) => { received = value; } });

    expect(received).toBe(error);
    expect(FakeSseClient.last).toBeNull();
  });

  it("chiude lo stream e annulla la richiesta quando il consumer si disiscrive", () => {
    let unsubscribed = false;
    api["generateMvpCommunication"].mockReturnValue(new Observable((observer) => {
      observer.next({ message: "Avviata", communicationId: 7, streamUrl: "/stream" });
      return () => { unsubscribed = true; };
    }));

    const subscription = service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" }).subscribe();
    const stream = FakeSseClient.last!;
    subscription.unsubscribe();

    expect(unsubscribed).toBe(true);
    expect(stream.closed).toBe(true);
  });

  it("completa anche quando done non include lo stato", () => {
    const emissions: CommunicationGenerationProgress[] = [];
    service.generate({ prompt: "Comunicazione di prova sufficientemente lunga", tone: "Chiaro e diretto", style: "Testo informativo" })
      .subscribe((progress) => emissions.push(progress));

    FakeSseClient.last!.emit("done", {});

    expect(setState).not.toHaveBeenCalled();
    expect(emissions.at(-1)).toMatchObject({ phase: "completed", communication: undefined });
  });

  it.each([
    ["updateCoverImage", "updateMvpCommunicationCoverImage", [7, expect.any(File)]],
    ["removeCoverImage", "removeMvpCommunicationCoverImage", [7]],
    ["discard", "discardMvpCommunication", [7]],
    ["saveToHistory", "saveMvpCommunication", [7]],
    [
      "saveConfiguration",
      "saveMvpPromptConfiguration",
      [{ prompt: "Un prompt qualsiasi lungo abbastanza", tone: "Tecnico", style: "Avviso operativo" }]
    ],
    ["deleteConfiguration", "deleteMvpPromptConfiguration", [1]],
    ["deleteFromHistory", "deleteMvpCommunication", [7]],
    ["rate", "rateMvpCommunication", [7, { rating: 4 }]],
    ["update", "updateMvpCommunication", [7, { title: "Titolo", body: "Corpo aggiornato" }]],
    ["favorite", "favoriteMvpCommunication", [7]],
    ["unfavorite", "unfavoriteMvpCommunication", [7]]
  ])("%s delega all'API e sincronizza lo store", (method, apiMethod, args) => {
    const state = { assistant: {}, copilot: {} };
    api[apiMethod].mockReturnValue(of({ state }));
    const image = new File(["cover"], "cover.png", { type: "image/png" });
    const actualArgs = method === "updateCoverImage" ? [7, image] : args;
    const expectedApiArgs = method === "updateCoverImage" ? [7, { image }] : actualArgs;

    (service[method as keyof AssistantService] as (...parameters: unknown[]) => Observable<unknown>)(...actualArgs).subscribe();

    expect(api[apiMethod]).toHaveBeenCalledWith(...expectedApiArgs);
    expect(setState).toHaveBeenCalledWith(state);
  });

  it.each([
    ["favorite", "favoriteMvpCommunication"],
    ["unfavorite", "unfavoriteMvpCommunication"]
  ])("%s propaga l'errore dell'API senza sincronizzare lo store", (method, apiMethod) => {
    const error = new Error("preferiti non disponibili");
    api[apiMethod].mockReturnValue(throwError(() => error));
    let received: unknown;

    (service[method as keyof AssistantService] as (id: number) => Observable<unknown>)(7)
      .subscribe({ error: (value: unknown) => { received = value; } });

    expect(received).toBe(error);
    expect(setState).not.toHaveBeenCalled();
  });

  it("inoltra i filtri valorizzati e normalizza quelli nulli", () => {
    const items = [{ id: 7 }, { id: 8 }];
    api["listMvpCommunications"].mockReturnValue(of({ items }));
    let result: unknown;

    service.searchCommunications({ keyword: "ferie", tone: null, style: "Breve", date: "" })
      .subscribe((value) => { result = value; });

    expect(api["listMvpCommunications"]).toHaveBeenCalledWith({
      keyword: "ferie",
      tone: undefined,
      style: "Breve",
      date: ""
    });
    expect(result).toBe(items);
  });
});
