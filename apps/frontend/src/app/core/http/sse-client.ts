import { Injectable } from "@angular/core";
import { environment } from "../../../environments/environment";
import { CORRELATION_ID_HEADER, REQUEST_ID_HEADER, generateRequestId } from "../observability/correlation";

export interface SseHandlers {
  onEvent(event: string, data: unknown): void;
  onNamedError(message: string): void;
  onConnectionError(): void;
}

/**
 * Client SSE basato su fetch: a differenza di EventSource puo' inviare gli
 * header di correlazione e distingue l'evento nominato `error` dal drop
 * della connessione (che EventSource collassa nello stesso handler).
 */
@Injectable({ providedIn: "root" })
export class SseClient {
  connect(url: string, handlers: SseHandlers): () => void {
    const controller = new AbortController();
    const requestId = generateRequestId();

    void readSseStream(resolveSseUrl(url), requestId, controller.signal, handlers);

    return () => controller.abort();
  }
}

export function resolveSseUrl(url: string): string {
  if (url.startsWith("http://") || url.startsWith("https://")) {
    return url;
  }

  return `${environment.apiBaseUrl}${url}`;
}

/**
 * Spezza un buffer SSE in frame completi (separati da una riga vuota) e
 * restituisce il residuo incompleto. I commenti (`: keepalive`) vengono ignorati.
 */
export function consumeSseBuffer(buffer: string, onFrame: (event: string, data: string) => void): string {
  const blocks = buffer.split("\n\n");
  const rest = blocks.pop() ?? "";

  for (const block of blocks) {
    let eventName = "message";
    const dataLines: string[] = [];

    for (const rawLine of block.split("\n")) {
      const line = rawLine.replace(/\r$/, "");

      if (line === "" || line.startsWith(":")) {
        continue;
      }

      if (line.startsWith("event:")) {
        eventName = line.slice("event:".length).trim();
        continue;
      }

      if (line.startsWith("data:")) {
        dataLines.push(line.slice("data:".length).trimStart());
      }
    }

    if (dataLines.length > 0) {
      onFrame(eventName, dataLines.join("\n"));
    }
  }

  return rest;
}

async function readSseStream(
  url: string,
  requestId: string,
  signal: AbortSignal,
  handlers: SseHandlers
): Promise<void> {
  try {
    const response = await fetch(url, {
      method: "GET",
      credentials: "include",
      headers: {
        Accept: "text/event-stream",
        [REQUEST_ID_HEADER]: requestId,
        [CORRELATION_ID_HEADER]: requestId
      },
      signal
    });

    if (!response.ok || response.body === null) {
      if (!signal.aborted) {
        handlers.onConnectionError();
      }

      return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = "";

    while (!signal.aborted) {
      const { done, value } = await reader.read();

      if (done) {
        break;
      }

      buffer += decoder.decode(value, { stream: true });
      buffer = consumeSseBuffer(buffer, (event, rawData) => dispatchSseFrame(event, rawData, handlers));
    }
  } catch {
    if (!signal.aborted) {
      handlers.onConnectionError();
    }
  }
}

function dispatchSseFrame(event: string, rawData: string, handlers: SseHandlers): void {
  let data: unknown = rawData;

  try {
    data = JSON.parse(rawData);
  } catch {
    data = { message: rawData };
  }

  if (event === "error") {
    const message =
      data !== null && typeof data === "object" && "message" in data && typeof data.message === "string"
        ? data.message
        : "Operazione non disponibile.";
    handlers.onNamedError(message);

    return;
  }

  handlers.onEvent(event, data);
}
