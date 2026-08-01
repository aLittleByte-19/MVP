import { HttpErrorResponse } from "@angular/common/http";
import { TestBed } from "@angular/core/testing";
import { GlobalErrorHandler } from "./global-error-handler";
import { LoggerService } from "./logger.service";

describe("GlobalErrorHandler", () => {
  let handler: GlobalErrorHandler;
  let logger: { error: jest.Mock };

  beforeEach(() => {
    logger = { error: jest.fn() };
    TestBed.configureTestingModule({
      providers: [GlobalErrorHandler, { provide: LoggerService, useValue: logger }]
    });
    handler = TestBed.inject(GlobalErrorHandler);
    jest.spyOn(console, "error").mockImplementation(() => undefined);
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it("ignora gli errori HTTP, gia' tracciati dall'interceptor", () => {
    // Loggarli anche qui vorrebbe dire due righe per lo stesso fallimento.
    handler.handleError(new HttpErrorResponse({ status: 500 }));

    expect(logger.error).not.toHaveBeenCalled();
  });

  it("registra il messaggio di un errore applicativo", () => {
    handler.handleError(new Error("Rendering fallito"));

    expect(logger.error).toHaveBeenCalledWith("Rendering fallito", { scope: "global" });
  });

  it("usa un messaggio generico per un valore che non e' un Error", () => {
    handler.handleError("boom");

    expect(logger.error).toHaveBeenCalledWith("Errore applicativo non gestito", { scope: "global" });
  });

  it("stampa lo stack quando c'e', per la diagnostica", () => {
    handler.handleError(new Error("con stack"));

    expect(console.error).toHaveBeenCalledTimes(1);
  });

  it("non stampa nulla in console quando lo stack manca", () => {
    const error = new Error("senza stack");
    error.stack = undefined;

    handler.handleError(error);

    expect(logger.error).toHaveBeenCalledWith("senza stack", { scope: "global" });
    expect(console.error).not.toHaveBeenCalled();
  });
});
