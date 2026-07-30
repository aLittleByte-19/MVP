import { HttpErrorResponse } from "@angular/common/http";

/**
 * Traduce un errore qualsiasi in un messaggio leggibile per l'utente.
 *
 * Il backend risponde con l'envelope `{ error: { message, code, requestId,
 * correlationId, fields? } }`: si usa il `message` quando presente. Per errori
 * di validazione si preferisce il primo messaggio di campo (es. limite caratteri).
 * Non si espongono mai dettagli tecnici (stack, corpo grezzo) nei messaggi
 * mostrati a video, in linea con OWASP ASVS.
 */
export function getApiErrorMessage(error: unknown, fallback = "Operazione non disponibile."): string {
  if (error instanceof HttpErrorResponse) {
    const fieldMessage = extractFirstFieldMessage(error.error);
    if (fieldMessage) {
      return fieldMessage;
    }

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

  return extractFirstFieldMessage(error) ?? extractEnvelopeMessage(error) ?? fallback;
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

function extractEnvelopeMessage(body: unknown): string | null {
  if (typeof body === "object" && body !== null && "error" in body) {
    const envelope = (body as { error?: { message?: string } }).error;

    if (envelope && typeof envelope.message === "string" && envelope.message.trim() !== "") {
      return envelope.message;
    }
  }

  return null;
}

function extractFirstFieldMessage(body: unknown): string | null {
  if (typeof body !== "object" || body === null || !("error" in body)) {
    return null;
  }

  const fields = (body as { error?: { fields?: Record<string, string[] | string> } }).error?.fields;
  if (!fields || typeof fields !== "object") {
    return null;
  }

  for (const value of Object.values(fields)) {
    if (Array.isArray(value) && typeof value[0] === "string" && value[0].trim() !== "") {
      return value[0];
    }

    if (typeof value === "string" && value.trim() !== "") {
      return value;
    }
  }

  return null;
}
