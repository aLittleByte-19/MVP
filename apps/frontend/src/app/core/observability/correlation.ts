/**
 * Header di correlazione condivisi con il backend Laravel.
 * Il middleware `CorrelateRequests` accetta in ingresso `X-Request-ID` e
 * `X-Correlation-ID`: generandoli lato browser le richieste originate dalla SPA
 * diventano correlabili end-to-end (browser -> proxy -> Laravel -> log/traces).
 */
export const REQUEST_ID_HEADER = "X-Request-ID";
export const CORRELATION_ID_HEADER = "X-Correlation-ID";

/**
 * Genera un identificativo opaco per la richiesta. Usa `crypto.randomUUID`
 * quando disponibile, con un fallback robusto per ambienti privi dell'API.
 */
export function generateRequestId(): string {
  const globalCrypto = globalThis.crypto;

  if (globalCrypto && typeof globalCrypto.randomUUID === "function") {
    return globalCrypto.randomUUID();
  }

  // Fallback non crittografico: sufficiente per la sola correlazione dei log.
  return "req-" + Date.now().toString(16) + "-" + Math.random().toString(16).slice(2, 10);
}
