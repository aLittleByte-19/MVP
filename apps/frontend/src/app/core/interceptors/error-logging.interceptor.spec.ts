import { HttpErrorResponse, HttpHeaders, HttpRequest, type HttpEvent } from "@angular/common/http";
import { TestBed } from "@angular/core/testing";
import { Observable, throwError, of } from "rxjs";
import { errorLoggingInterceptor } from "./error-logging.interceptor";
import { LoggerService } from "../observability/logger.service";
import { CORRELATION_ID_HEADER } from "../observability/correlation";

describe("errorLoggingInterceptor", () => {
  let logger: { error: jest.Mock };

  beforeEach(() => {
    logger = { error: jest.fn() };
    TestBed.configureTestingModule({
      providers: [{ provide: LoggerService, useValue: logger }]
    });
  });

  /** Esegue l'interceptor su una richiesta il cui esito e' deciso dal chiamante. */
  function run(
    request: HttpRequest<unknown>,
    outcome: Observable<HttpEvent<unknown>>
  ): { error?: unknown; completed: boolean } {
    const result: { error?: unknown; completed: boolean } = { completed: false };

    TestBed.runInInjectionContext(() => {
      errorLoggingInterceptor(request, () => outcome).subscribe({
        next: () => (result.completed = true),
        error: (error: unknown) => (result.error = error)
      });
    });

    return result;
  }

  it("registra stato, metodo ed endpoint del fallimento", () => {
    const request = new HttpRequest("GET", "/api/v1/documents");
    const failure = new HttpErrorResponse({ status: 503, url: "/api/v1/documents" });

    run(request, throwError(() => failure));

    expect(logger.error).toHaveBeenCalledWith("Richiesta HTTP fallita", {
      scope: "/api/v1/documents",
      status: 503,
      method: "GET",
      correlationId: null
    });
  });

  it("toglie la query string dall'endpoint registrato", () => {
    // La query puo' contenere i filtri digitati dall'utente: nei log resta
    // solo l'endpoint, che e' il segnale utile senza il dato personale.
    const request = new HttpRequest("GET", "/api/v1/documents?search=Rossi&year=2026");

    run(request, throwError(() => new HttpErrorResponse({ status: 500 })));

    expect(logger.error).toHaveBeenCalledWith(
      "Richiesta HTTP fallita",
      expect.objectContaining({ scope: "/api/v1/documents" })
    );
  });

  it("preferisce il correlation id restituito dalla risposta", () => {
    const request = new HttpRequest("POST", "/api/v1/communications", {});
    const failure = new HttpErrorResponse({
      status: 422,
      headers: new HttpHeaders({ "X-Correlation-ID": "dalla-risposta" })
    });

    run(request, throwError(() => failure));

    expect(logger.error).toHaveBeenCalledWith(
      "Richiesta HTTP fallita",
      expect.objectContaining({ correlationId: "dalla-risposta" })
    );
  });

  it("ripiega sul correlation id inviato con la richiesta", () => {
    const request = new HttpRequest("POST", "/api/v1/communications", {}, {
      headers: new HttpHeaders({ [CORRELATION_ID_HEADER]: "dalla-richiesta" })
    });

    run(request, throwError(() => new HttpErrorResponse({ status: 500 })));

    expect(logger.error).toHaveBeenCalledWith(
      "Richiesta HTTP fallita",
      expect.objectContaining({ correlationId: "dalla-richiesta" })
    );
  });

  it("rilancia l'errore, perche' la gestione utente resta alle feature", () => {
    const failure = new HttpErrorResponse({ status: 500 });

    const result = run(new HttpRequest("GET", "/api/v1/documents"), throwError(() => failure));

    expect(result.error).toBe(failure);
  });

  it("non registra nulla per un errore che non e' HTTP", () => {
    const boom = new Error("interruzione dello stream");

    const result = run(new HttpRequest("GET", "/api/v1/documents"), throwError(() => boom));

    expect(logger.error).not.toHaveBeenCalled();
    expect(result.error).toBe(boom);
  });

  it("lascia passare le richieste andate a buon fine senza toccarle", () => {
    const request = new HttpRequest("GET", "/api/v1/documents");

    const result = run(request, of({ type: 0 } as HttpEvent<unknown>));

    expect(logger.error).not.toHaveBeenCalled();
    expect(result.completed).toBe(true);
  });
});
