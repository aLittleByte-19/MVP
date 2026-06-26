/**
 * Configurazione di runtime per la build di produzione.
 *
 * `apiBaseUrl` resta vuoto: la SPA e l'API Laravel sono serviti dalla stessa
 * origine dietro Nginx/Traefik, quindi le chiamate usano percorsi relativi
 * (`/api/v1/...`). Il valore e' comunque configurabile per scenari in cui il
 * frontend venga servito da un'origine distinta dal backend.
 */
export const environment = {
  production: true,
  apiBaseUrl: ""
};
