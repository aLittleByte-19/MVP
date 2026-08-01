# Confine di autenticazione/autorizzazione

L'autenticazione è intenzionalmente simulata in questa MVP (vedi
[ADR 0007](../architecture-decisions/0007-authn-authz-boundary.md)).

## Implementato

- Il middleware `mvp.identity` risolve un `MvpUser` strutturato.
- La modalità locale inietta utente/tenant/ruolo deterministici da configurazione.
- La modalità trusted-header richiede header di identità completi.
- Il middleware `mvp.authorize` richiede i ruoli configurati (`mvp-operator`/`mvp-admin`).
- Le API documentali verificano l'ownership per tenant prima di stream, preview o delete.
- `/admin` e i path legacy di amministrazione runtime restituiscono 404 tramite Nginx.

## Non implementato (fuori scope MVP)

- Login OIDC enterprise.
- Validazione JWT/JWKS.
- Sincronizzazione SCIM/gruppi.
- Hardening di sessione di produzione oltre alla configurazione locale Redis/sessione.
- Modello di policy RBAC/ABAC definitivo.

## Motivazione

L'obiettivo della MVP è validare la pipeline AI documentale, l'orchestrazione del workflow, il
confine di storage e l'osservabilità. L'autenticazione reale appartiene al confine di
deployment/piattaforma e richiede dettagli dell'IdP aziendale non disponibili in questo
repository. In modalità `trusted_headers`, senza un gateway che firmi gli header `X-Mvp-*`,
questi sono falsificabili: è un limite dichiarato del confine simulato.

## Direzione verso la produzione

1. Terminare OIDC all'edge o su un API gateway.
2. Validare i token firmati lato server o ricevere claim verificati da un gateway fidato.
3. Mappare i gruppi aziendali sui ruoli applicativi.
4. Sostituire la modalità locale con una configurazione di produzione deny-by-default.
5. Emettere audit event per le azioni negate nei flussi privilegiati.
