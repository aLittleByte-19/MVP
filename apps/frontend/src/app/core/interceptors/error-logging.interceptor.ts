import type { HttpInterceptorFn } from "@angular/common/http";
import { HttpErrorResponse } from "@angular/common/http";
import { inject } from "@angular/core";
import { catchError, throwError } from "rxjs";
import { LoggerService } from "../observability/logger.service";
import { CORRELATION_ID_HEADER } from "../observability/correlation";
import { extractCorrelationId } from "../errors/api-error";

/**
 * Traccia in modo strutturato i fallimenti HTTP (status, metodo, endpoint,
 * correlation id) e poi rilancia l'errore: la gestione utente resta a carico
 * delle feature. Non si registra mai il corpo della risposta per evitare leak di
 * dati sensibili (OWASP ASVS / Google SRE: segnali utili, niente PII).
 */
export const errorLoggingInterceptor: HttpInterceptorFn = (req, next) => {
  const logger = inject(LoggerService);

  return next(req).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse) {
        logger.error("Richiesta HTTP fallita", {
          scope: stripQuery(req.url),
          status: error.status,
          method: req.method,
          correlationId: extractCorrelationId(error) ?? req.headers.get(CORRELATION_ID_HEADER)
        });
      }

      return throwError(() => error);
    })
  );
};

function stripQuery(url: string): string {
  const index = url.indexOf("?");

  return index === -1 ? url : url.slice(0, index);
}
