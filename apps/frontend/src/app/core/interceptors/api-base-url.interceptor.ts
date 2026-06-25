import type { HttpInterceptorFn } from "@angular/common/http";
import { environment } from "../../../environments/environment";

/**
 * Premette `environment.apiBaseUrl` alle sole richieste applicative (`/api`,
 * `/health`, `/ready`) quando configurato. Di default e' vuoto: SPA e API
 * condividono l'origine dietro il reverse proxy, quindi e' un no-op. Resta il
 * punto unico per puntare il frontend a un backend su origine diversa.
 */
export const apiBaseUrlInterceptor: HttpInterceptorFn = (req, next) => {
  const baseUrl = environment.apiBaseUrl;

  if (!baseUrl || !isRelativeApiUrl(req.url)) {
    return next(req);
  }

  const normalizedBase = baseUrl.replace(/\/$/, "");

  return next(req.clone({ url: normalizedBase + req.url }));
};

function isRelativeApiUrl(url: string): boolean {
  return url.startsWith("/api") || url.startsWith("/health") || url.startsWith("/ready");
}
