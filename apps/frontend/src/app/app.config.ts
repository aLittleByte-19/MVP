import { type ApplicationConfig, ErrorHandler, provideZoneChangeDetection } from "@angular/core";
import { provideRouter, withInMemoryScrolling } from "@angular/router";
import { provideHttpClient, withInterceptors } from "@angular/common/http";
import { routes } from "./app.routes";
import { apiBaseUrlInterceptor } from "./core/interceptors/api-base-url.interceptor";
import { correlationInterceptor } from "./core/interceptors/correlation.interceptor";
import { errorLoggingInterceptor } from "./core/interceptors/error-logging.interceptor";
import { GlobalErrorHandler } from "./core/observability/global-error-handler";

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes, withInMemoryScrolling({ anchorScrolling: "disabled", scrollPositionRestoration: "disabled" })),
    provideHttpClient(
      // Ordine: base URL -> correlazione -> logging, cosi' il log vede l'URL finale.
      withInterceptors([apiBaseUrlInterceptor, correlationInterceptor, errorLoggingInterceptor])
    ),
    { provide: ErrorHandler, useClass: GlobalErrorHandler }
  ]
};
