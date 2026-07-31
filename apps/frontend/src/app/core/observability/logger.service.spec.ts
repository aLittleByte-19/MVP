import { TestBed } from "@angular/core/testing";
import { LoggerService } from "./logger.service";

/**
 * `isDevMode()` non e' sostituibile con uno spy: gli export di @angular/core
 * non sono riconfigurabili. La funzione legge pero' il flag globale
 * `ngDevMode`, che e' la leva prevista per simulare la modalita' produzione.
 */
declare const globalThis: { ngDevMode?: unknown };

describe("LoggerService", () => {
  let service: LoggerService;

  beforeEach(() => {
    service = TestBed.inject(LoggerService);
    jest.spyOn(console, "info").mockImplementation(() => undefined);
    jest.spyOn(console, "warn").mockImplementation(() => undefined);
    jest.spyOn(console, "error").mockImplementation(() => undefined);
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  /** Ultima riga passata al sink, gia' riportata a oggetto. */
  function lastEntry(sink: jest.SpyInstance): Record<string, unknown> {
    return JSON.parse(sink.mock.calls.at(-1)?.[0] as string) as Record<string, unknown>;
  }

  it("emette una riga strutturata con livello, sorgente e timestamp", () => {
    service.error("Richiesta HTTP fallita", { scope: "/api/v1/documents", status: 500 });

    const entry = lastEntry(console.error as unknown as jest.SpyInstance);

    expect(entry).toMatchObject({
      level: "error",
      source: "mvp-frontend",
      message: "Richiesta HTTP fallita",
      scope: "/api/v1/documents",
      status: 500
    });
    expect(typeof entry["ts"]).toBe("string");
    expect(Number.isNaN(Date.parse(entry["ts"] as string))).toBe(false);
  });

  it("manda ogni livello sul suo canale", () => {
    service.info("informativo");
    service.warn("attenzione");
    service.error("errore");

    expect(console.info).toHaveBeenCalledTimes(1);
    expect(console.warn).toHaveBeenCalledTimes(1);
    expect(console.error).toHaveBeenCalledTimes(1);
  });

  it("funziona anche senza contesto", () => {
    service.warn("solo messaggio");

    expect(lastEntry(console.warn as unknown as jest.SpyInstance)).toMatchObject({
      level: "warn",
      message: "solo messaggio"
    });
  });

  it("porta con se' il correlation id quando c'e'", () => {
    service.error("fallita", { correlationId: "abc-123" });

    expect(lastEntry(console.error as unknown as jest.SpyInstance)["correlationId"]).toBe("abc-123");
  });

  it("in produzione tace sugli info ma non su warn ed error", () => {
    // Il livello info serve in sviluppo: in produzione sarebbe solo rumore.
    const previous = globalThis.ngDevMode;
    globalThis.ngDevMode = false;

    try {
      service.info("questo non deve uscire");
      service.warn("questo si'");
      service.error("anche questo");
    } finally {
      globalThis.ngDevMode = previous;
    }

    expect(console.info).not.toHaveBeenCalled();
    expect(console.warn).toHaveBeenCalledTimes(1);
    expect(console.error).toHaveBeenCalledTimes(1);
  });
});
