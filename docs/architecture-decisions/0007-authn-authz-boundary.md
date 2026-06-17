# ADR 0007 — Confine di autenticazione/autorizzazione

Status: Accepted, implemented baseline
Date: 2026-06-08

## Context

Il runtime deve simulare che l'autenticazione sia già avvenuta tramite un IdP aziendale,
implementando però l'autorizzazione in Laravel. La PoC non deve dipendere da un IdP reale, ma il
modello di identità deve essere equivalente a quello di un confine OIDC/SAML enterprise.

## Decision

Non implementare un IdP reale in questa PoC. Aggiungere un middleware di identità solo
locale/di test (`poc.identity`) che inietta claim strutturati equivalenti a quelli OIDC/SAML
enterprise: user ID, email, display name, tenant/azienda, gruppi/ruoli e attributi applicativi.

Usare policy/servizi Laravel per applicare RBAC e ABAC lato server. Il frontend può nascondere
le azioni non disponibili per UX, ma non deve mai essere la fonte di verità per l'autorizzazione.

## Consequences

- La simulazione di identità locale deve essere chiaramente isolata dalla modalità di produzione.
- I test di autorizzazione devono coprire accessi consentiti e negati.
- Le decisioni di accesso negate rilevanti e gli eventi di autorizzazione sensibili devono essere
  registrati nell'audit.
- In modalità `trusted_headers` gli header `X-Poc-*` sono falsificabili senza un gateway che li
  firmi: è un limite dichiarato del confine simulato.

## Alternatives considered

- **Integrare un IdP reale (Cognito/Keycloak/Entra) nella PoC**: rinviato perché fuori dal
  perimetro PoC e non necessario a dimostrare l'autorizzazione lato server.
- **Autorizzazione lato frontend**: scartata perché la UI non può essere fonte di verità per
  l'access control.

## Implementation evidence

- Middleware: `app/Http/Middleware/ResolvePocIdentity.php` (modalità `local`/`trusted_headers`),
  `app/Copilot/Identity/PocUser.php`.
- Autorizzazione: `app/Http/Middleware/AuthorizePocAccess.php` (ruoli `poc-operator`/`poc-admin`,
  tenant check) e check nei controller.
- Configurazione identità in `infra/localstack/main.tf` (`POC_IDENTITY_MODE`, `POC_LOCAL_*`).

## References

- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- GitHub Actions — AWS OIDC per le credenziali CI future: https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services

## Related documents

- [`0001-frontend-spa.md`](0001-frontend-spa.md)
- [`0002-laravel-api-json.md`](0002-laravel-api-json.md)
- [`../security/auth-boundary.md`](../security/auth-boundary.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§13)
