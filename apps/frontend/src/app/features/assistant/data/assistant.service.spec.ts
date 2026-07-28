import { Injector } from "@angular/core";
import { of } from "rxjs";
import { AlittlebyteMVPAPIService } from "../../../../api/generated/mvp-api";
import { MvpStateStore } from "../../../core/state/mvp-state.store";
import { AssistantService } from "./assistant.service";
import type { CommunicationGenerationProgress } from "../assistant.model";

/** EventSource minimale: registra i listener e li lascia scatenare dal test. */
class FakeEventSource {
  static last: FakeEventSource | null = null;

  readonly listeners = new Map<string, (event: MessageEvent) => void>();
  closed = false;

  constructor(readonly url: string) {
    FakeEventSource.last = this;
  }

  addEventListener(type: string, listener: (event: MessageEvent) => void): void {
    this.listeners.set(type, listener);
  }

  close(): void {
    this.closed = true;
  }

  emit(type: string, data: unknown): void {
    this.listeners.get(type)?.({ data: JSON.stringify(data) } as MessageEvent);
  }
}

describe("AssistantService", () => {
  let service: AssistantService;
  let setState: jest.Mock;
  let reload: jest.Mock;

  beforeEach(() => {
    (globalThis as unknown as { EventSource: unknown }).EventSource = FakeEventSource;
    FakeEventSource.last = null;
    setState = jest.fn();
    reload = jest.fn();

    const injector = Injector.create({
      providers: [
        {
          provide: AlittlebyteMVPAPIService,
          useValue: {
            generateMvpCommunication: () =>
              of({ message: "Generazione avviata.", communicationId: 7, streamUrl: "/api/v1/communications/7/stream" }),
            regenerateMvpCommunication: () =>
              of({ message: "Rigenerazione avviata.", communicationId: 7, streamUrl: "/api/v1/communications/7/stream" })
          }
        },
        { provide: MvpStateStore, useValue: { setState, reload } },
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

    const stream = FakeEventSource.last!;
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

    const stream = FakeEventSource.last!;
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

    const stream = FakeEventSource.last!;
    stream.emit("text", { title: "Titolo nuovo", body: "Corpo nuovo" });
    stream.emit("done", { communication: { id: 7 }, state: { assistant: {}, copilot: {} } });

    expect(emissions[0].status).toBe("Rigenerazione avviata.");
    expect(emissions[emissions.length - 1].phase).toBe("completed");
    expect(setState).toHaveBeenCalledTimes(1);
    expect(stream.closed).toBe(true);
  });
});
