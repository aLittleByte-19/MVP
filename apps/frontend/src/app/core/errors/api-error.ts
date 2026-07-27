import { HttpErrorResponse } from "@angular/common/http";

/**
 * Traduce un errore qualsiasi in un messaggio leggibile per l'utente.
 *
 * Il backend risponde con l'envelope `{ error: { message, code, requestId,
 * correlationId } }`: si usa il `message` quando presente. Non si espongono mai
 * dettagli tecnici (stack, corpo grezzo) nei messaggi mostrati a video, in linea
 * con OWASP ASVS (gestione errori senza leak informativi).
 */
export function getApiErrorMessage(error: unknown, fallback = "Operazione non disponibile."): string {
  if (error instanceof HttpErrorResponse) {
    const message = extractEnvelopeMessage(error.error);

    if (message) {
      return message;
    }

    if (error.status === 0) {
      return "Servizio non raggiungibile. Verifica la connessione e riprova.";
    }

    return fallback;
  }

  if (error instanceof Error) {
    return error.message || fallback;
  }

  return extractEnvelopeMessage(error) ?? fallback;
}

/** Estrae l'eventuale correlation id dall'envelope di errore, per la sola diagnostica. */
export function extractCorrelationId(error: unknown): string | null {
  if (error instanceof HttpErrorResponse) {
    const headerValue = error.headers?.get("X-Correlation-ID");

    if (headerValue) {
      return headerValue;
    }

    const body = error.error;

    if (typeof body === "object" && body !== null && "error" in body) {
      const envelope = (body as { error?: { correlationId?: string | null } }).error;

      return envelope?.correlationId ?? null;
    }
  }

  return null;
}

/**
 * Estrae gli errori di validazione per-campo dall'envelope (`error.fields`,
 * popolato dal backend da `ValidationException::errors()`), per evidenziare
 * il campo incriminato invece di mostrare solo un messaggio generico
 * (UC-55/UC-69: "il sistema segnala il campo errato").
 */
export function extractFieldErrors(error: unknown): Record<string, string> | null {
  if (!(error instanceof HttpErrorResponse)) {
    return null;
  }

  const body = error.error as { error?: { fields?: Record<string, string[]> } } | null;
  const fields = body?.error?.fields;

  if (!fields || typeof fields !== "object") {
    return null;
  }

  return Object.fromEntries(
    Object.entries(fields)
      .filter(([, messages]) => Array.isArray(messages) && messages.length > 0)
      .map(([field, messages]) => [field, messages[0]])
  );
}

function extractEnvelopeMessage(body: unknown): string | null {
  if (typeof body === "object" && body !== null && "error" in body) {
    const envelope = (body as { error?: { message?: string } }).error;

    if (envelope && typeof envelope.message === "string" && envelope.message.trim() !== "") {
      return envelope.message;
    }
  }

  return null;
}
