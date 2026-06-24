import type { HttpInterceptorFn } from "@angular/common/http";
import {
  CORRELATION_ID_HEADER,
  REQUEST_ID_HEADER,
  generateRequestId
} from "../observability/correlation";

/**
 * Propaga gli header di correlazione verso Laravel per ogni richiesta API.
 * Il middleware backend li riusa se presenti, cosi' la richiesta originata dalla
 * SPA si ritrova negli stessi log/traces lato server. Header tecnici, nessun
 * dato sensibile.
 */
export const correlationInterceptor: HttpInterceptorFn = (req, next) => {
  if (req.headers.has(REQUEST_ID_HEADER)) {
    return next(req);
  }

  const requestId = generateRequestId();

  return next(
    req.clone({
      setHeaders: {
        [REQUEST_ID_HEADER]: requestId,
        [CORRELATION_ID_HEADER]: requestId
      }
    })
  );
};
