# Documentazione MVP - aLittleByte

Documentazione tecnica della MVP sviluppata nel contesto progettuale Eggon/NEXUM.

Punto d'ingresso alla documentazione tecnica.

Per setup e avvio rapido vedi il [README di progetto](../README.md).

## Fonti di verità documentali

| Tema | Documento di riferimento |
| --- | --- |
| Setup e avvio locale | [README di progetto](../README.md) e [`runbooks/local-development.md`](runbooks/local-development.md) |
| Sicurezza applicativa | [`security/`](security/) |
| Operatività e troubleshooting | [`runbooks/`](runbooks/) |

## Operazioni (runbook)

- [Sviluppo locale production-like](runbooks/local-development.md): avvio e uso dello stack.
- [Pipeline documentale](runbooks/document-pipeline.md): flusso Co-Pilot end-to-end.
- [Pipeline comunicazioni](runbooks/communication-pipeline.md): flusso AI Assistant end-to-end.
- [DLQ e recovery](runbooks/dlq-recovery.md): gestione job falliti e ripristino.
- [Backup/restore locale](runbooks/backup-restore-local.md): PostgreSQL.
- [CI/CD](runbooks/ci-cd.md): pipeline e quality gate.
- [Permessi AWS necessari](runbooks/aws-permissions-needed.md): per il passaggio ad AWS reale.
- [Matrice di verifica](operations/verify.md): come validare il comportamento atteso.

## Sicurezza

- [Confine di autenticazione/autorizzazione](security/auth-boundary.md): identità, RBAC/ABAC.
- [Matrice permessi IAM](security/iam-permissions-matrix.md): minimo privilegio sulle risorse.
- [OWASP ASVS Mapping](security/owasp-asvs-mapping.md): aderenza ai controlli ASVS.

## Riferimenti esterni alla cartella `docs/`

- [`openapi/v1/`](../openapi/v1/): contratto API OpenAPI (fonte del client generato).
- [`infra/localstack/`](../infra/localstack/): Terraform e risorse AWS-like locali.
- [`docker/`](../docker/): configurazioni runtime ed edge.
- [`.github/workflows/`](../.github/workflows/): pipeline CI e quality gate.

## Terminologia

Per evitare ambiguità ricorrenti, in tutta la documentazione i termini hanno questo significato:

- **Capitolato**: il documento `[NEXUM] BRD-FASE02-2025` (Business Requirements Document, C5).
  È il **documento di riferimento per i requisiti di business**.
- **MVP**: questa MVP: ambiente locale e riproducibile che emula i servizi AWS
  tramite LocalStack, non un deploy di produzione.
- **AWS-like / emulato**: servizio AWS riprodotto in locale via LocalStack (es. SQS, S3, KMS,
  Step Functions); stesso modello di interazione, infrastruttura non gestita.
