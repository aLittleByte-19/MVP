import { ErrorHandler, Injectable, inject } from "@angular/core";
import { HttpErrorResponse } from "@angular/common/http";
import { LoggerService } from "./logger.service";

/**
 * Gestione centralizzata degli errori non catturati dell'applicazione.
 * Gli errori HTTP sono gia' tracciati dall'interceptor dedicato e gestiti dalle
 * feature, quindi qui si evita il doppio log: si registra solo cio' che
 * sfuggirebbe altrimenti (errori di rendering/logica), senza dati sensibili.
 */
@Injectable()
export class GlobalErrorHandler implements ErrorHandler {
  private readonly logger = inject(LoggerService);

  handleError(error: unknown): void {
    if (error instanceof HttpErrorResponse) {
      // Gia' loggato e mappato a livello di interceptor/feature.
      return;
    }

    const message = error instanceof Error ? error.message : "Errore applicativo non gestito";
    this.logger.error(message, { scope: "global" });

    if (error instanceof Error && error.stack) {
      console.error(error.stack);
    }
  }
}
