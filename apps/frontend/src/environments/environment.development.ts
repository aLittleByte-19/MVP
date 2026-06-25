/**
 * Configurazione di runtime per `ng serve`.
 *
 * In sviluppo le chiamate `/api`, `/health`, `/ready` sono inoltrate
 * all'entrypoint TLS di Traefik tramite `proxy.conf.json`, quindi anche qui i
 * percorsi restano relativi (`apiBaseUrl` vuoto).
 */
export const environment = {
  production: false,
  apiBaseUrl: ""
};
