import { HttpErrorResponse, HttpHeaders } from "@angular/common/http";
import { extractCorrelationId, extractFieldErrors, getApiErrorMessage } from "./api-error";

/**
 * Costruisce una risposta di errore con l'envelope che produce il backend
 * (`{ error: { message, code, requestId, correlationId, fields? } }`).
 */
function httpError(
  body: unknown,
  options: { status?: number; headers?: Record<string, string> } = {}
): HttpErrorResponse {
  return new HttpErrorResponse({
    error: body,
    status: options.status ?? 500,
    headers: options.headers ? new HttpHeaders(options.headers) : undefined
  });
}

describe("getApiErrorMessage", () => {
  it("usa il messaggio dell'envelope", () => {
    const error = httpError({ error: { message: "Documento non trovato." } }, { status: 404 });

    expect(getApiErrorMessage(error)).toBe("Documento non trovato.");
  });

  it("preferisce il primo errore di campo al messaggio generico", () => {
    // Su una validazione il messaggio d'envelope e' generico ("dati non
    // validi"): dire quale campo e' sbagliato e' l'unica informazione utile.
    const error = httpError(
      {
        error: {
          message: "I dati inviati non sono validi.",
          fields: { prompt: ["Il prompt non puo' superare i 2000 caratteri."] }
        }
      },
      { status: 422 }
    );

    expect(getApiErrorMessage(error)).toBe("Il prompt non puo' superare i 2000 caratteri.");
  });

  it("accetta un errore di campo espresso come stringa invece che come lista", () => {
    const error = httpError({ error: { fields: { tone: "Tono non riconosciuto." } } }, { status: 422 });

    expect(getApiErrorMessage(error)).toBe("Tono non riconosciuto.");
  });

  it("scavalca i campi vuoti e prende il primo messaggio utile", () => {
    const error = httpError(
      { error: { fields: { search: [], month: ["   "], year: ["Anno non valido."] } } },
      { status: 422 }
    );

    expect(getApiErrorMessage(error)).toBe("Anno non valido.");
  });

  it("segnala il servizio irraggiungibile quando lo stato e' 0", () => {
    // status 0 e' la rete caduta o la richiesta bloccata: un "errore generico"
    // qui manderebbe l'utente a cercare un problema che non e' nei suoi dati.
    expect(getApiErrorMessage(httpError(null, { status: 0 }))).toBe(
      "Servizio non raggiungibile. Verifica la connessione e riprova."
    );
  });

  it("ripiega sul messaggio di default quando l'envelope non dice nulla", () => {
    expect(getApiErrorMessage(httpError({ qualcosa: "altro" }, { status: 500 }))).toBe(
      "Operazione non disponibile."
    );
  });

  it("rispetta il messaggio di default passato dal chiamante", () => {
    expect(getApiErrorMessage(httpError(null, { status: 500 }), "Upload fallito.")).toBe("Upload fallito.");
  });

  it("non espone dettagli tecnici quando il corpo e' una stringa grezza", () => {
    const message = getApiErrorMessage(httpError("<html>Internal Server Error</html>", { status: 500 }));

    expect(message).toBe("Operazione non disponibile.");
  });

  it("usa il messaggio di un Error normale", () => {
    expect(getApiErrorMessage(new Error("Timeout della richiesta"))).toBe("Timeout della richiesta");
  });

  it("ripiega sul default per un Error senza messaggio", () => {
    expect(getApiErrorMessage(new Error(""))).toBe("Operazione non disponibile.");
  });

  it("legge l'envelope anche quando non arriva da HttpErrorResponse", () => {
    expect(getApiErrorMessage({ error: { message: "Errore dallo stream." } })).toBe("Errore dallo stream.");
  });

  it("ripiega sul default per un valore che non e' un errore", () => {
    expect(getApiErrorMessage("boom")).toBe("Operazione non disponibile.");
    expect(getApiErrorMessage(null)).toBe("Operazione non disponibile.");
    expect(getApiErrorMessage(undefined)).toBe("Operazione non disponibile.");
  });

  it("ignora un messaggio d'envelope fatto di soli spazi", () => {
    expect(getApiErrorMessage(httpError({ error: { message: "   " } }, { status: 500 }))).toBe(
      "Operazione non disponibile."
    );
  });
});

describe("extractCorrelationId", () => {
  it("preferisce l'header X-Correlation-ID", () => {
    const error = httpError(
      { error: { correlationId: "dal-corpo" } },
      { headers: { "X-Correlation-ID": "dall-header" } }
    );

    expect(extractCorrelationId(error)).toBe("dall-header");
  });

  it("ripiega sul correlationId dell'envelope", () => {
    expect(extractCorrelationId(httpError({ error: { correlationId: "abc-123" } }))).toBe("abc-123");
  });

  it("restituisce null quando l'envelope non lo contiene", () => {
    expect(extractCorrelationId(httpError({ error: { message: "Errore" } }))).toBeNull();
  });

  it("restituisce null per un corpo senza envelope", () => {
    expect(extractCorrelationId(httpError("testo grezzo"))).toBeNull();
  });

  it("restituisce null per un errore che non e' HTTP", () => {
    expect(extractCorrelationId(new Error("boom"))).toBeNull();
    expect(extractCorrelationId(null)).toBeNull();
  });
});

describe("extractFieldErrors", () => {
  it("riduce ogni campo al suo primo messaggio", () => {
    const error = httpError(
      {
        error: {
          fields: {
            recipientEmail: ["Indirizzo non valido.", "Formato atteso: nome@dominio"],
            fiscalCode: ["Codice fiscale non valido."]
          }
        }
      },
      { status: 422 }
    );

    expect(extractFieldErrors(error)).toEqual({
      recipientEmail: "Indirizzo non valido.",
      fiscalCode: "Codice fiscale non valido."
    });
  });

  it("scarta i campi senza messaggi", () => {
    const error = httpError({ error: { fields: { a: [], b: ["Errore su b."] } } }, { status: 422 });

    expect(extractFieldErrors(error)).toEqual({ b: "Errore su b." });
  });

  it("restituisce null quando l'envelope non ha la sezione fields", () => {
    expect(extractFieldErrors(httpError({ error: { message: "Errore" } }))).toBeNull();
  });

  it("restituisce null per un errore che non e' HTTP", () => {
    expect(extractFieldErrors(new Error("boom"))).toBeNull();
  });
});
