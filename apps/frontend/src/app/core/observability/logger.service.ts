import { Injectable, isDevMode } from "@angular/core";

export type LogLevel = "info" | "warn" | "error";

export interface LogContext {
  /** Identificativo di correlazione propagato al backend, se disponibile. */
  correlationId?: string | null;
  /** Endpoint/area logica coinvolta (mai il payload). */
  scope?: string;
  /** Codice di stato HTTP per gli errori di rete. */
  status?: number;
  [key: string]: unknown;
}

/**
 * Logger frontend strutturato e volutamente parco.
 *
 * Principi (Google SRE / OWASP ASVS): segnali diagnostici utili senza rumore e
 * senza mai registrare dati sensibili. Il logger riceve solo metadati tecnici
 * (endpoint, stato HTTP, correlation id): non si passano mai token, header di
 * autenticazione, PII o corpi di richiesta/risposta.
 */
@Injectable({ providedIn: "root" })
export class LoggerService {
  info(message: string, context: LogContext = {}): void {
    this.emit("info", message, context);
  }

  warn(message: string, context: LogContext = {}): void {
    this.emit("warn", message, context);
  }

  error(message: string, context: LogContext = {}): void {
    this.emit("error", message, context);
  }

  private emit(level: LogLevel, message: string, context: LogContext): void {
    const entry = {
      ts: new Date().toISOString(),
      level,
      source: "poc-frontend",
      message,
      ...context
    };

    // In produzione si mantengono solo warn/error per ridurre il rumore.
    if (level === "info" && !isDevMode()) {
      return;
    }

    const sink = level === "error" ? console.error : level === "warn" ? console.warn : console.info;
    sink(JSON.stringify(entry));
  }
}
