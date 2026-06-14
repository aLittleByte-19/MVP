# Documentazione PoC - aLittleByte

Documentazione tecnica della Proof of Concept sviluppata nel contesto progettuale Eggon/NEXUM.

Punto d'ingresso alla documentazione tecnica. Se è la prima volta che apri il progetto, segui
il percorso di lettura qui sotto; le sezioni successive sono un riferimento tematico.

Per setup e avvio rapido vedi il [README di progetto](../README.md).

## Fonti di verità documentali

Per evitare informazioni duplicate o divergenti, ogni tema ha **un** documento di riferimento:

| Tema | Documento di riferimento |
| --- | --- |
| Perimetro funzionale della PoC | [`poc-scope.md`](poc-scope.md) |
| Stato implementativo reale | [`IMPLEMENTATION_OVERVIEW.md`](IMPLEMENTATION_OVERVIEW.md) |
| Decisioni architetturali | [`architecture-decisions/`](architecture-decisions/README.md) |
| Tracciabilità rispetto al Capitolato | [`architecture/capitolato-traceability.md`](architecture/capitolato-traceability.md) |
| Architettura runtime | [`architecture/final-architecture.md`](architecture/final-architecture.md) |
| Struttura del repository | [`architecture/repository-structure.md`](architecture/repository-structure.md) |
| Setup e avvio locale | [README di progetto](../README.md) e [`runbooks/local-development.md`](runbooks/local-development.md) |
| Sicurezza applicativa | [`security/`](security/) |
| Operatività e troubleshooting | [`runbooks/`](runbooks/) |
| Gap production-like | [`IMPLEMENTATION_OVERVIEW.md`](IMPLEMENTATION_OVERVIEW.md) (§18–19) e i mapping [ASVS](security/owasp-asvs-mapping.md) / [Well-Architected](architecture/aws-well-architected-mapping.md) |

## Percorso di lettura consigliato

1. **[Perimetro funzionale](poc-scope.md)** — cosa fa (e cosa non fa) la PoC, area per area.
2. **[Panoramica implementativa](IMPLEMENTATION_OVERVIEW.md)** — come è costruito l'applicativo.
3. **[Struttura del repository](architecture/repository-structure.md)** — dove sta cosa.
4. **[Architettura finale](architecture/final-architecture.md)** — vista d'insieme di runtime e infrastruttura.
5. **[Tracciabilità dal Capitolato](architecture/capitolato-traceability.md)** — perché ogni scelta tecnica, con citazione del requisito.

## Architettura e decisioni

- [Architettura finale](architecture/final-architecture.md) — runtime, dati, workflow, osservabilità.
- [Architecture Decision Records](architecture-decisions/README.md) — le decisioni architetturali e il loro razionale.
- [Tracciabilità dal Capitolato](architecture/capitolato-traceability.md) — mappatura requisiti → scelte tecniche.
- [AWS Well-Architected Mapping](architecture/aws-well-architected-mapping.md) — aderenza ai pilastri AWS.
- [Valutazione RLS PostgreSQL](architecture/postgres-rls-assessment.md) — analisi del Row-Level Security.
- Diagrammi sorgente in [`architecture/diagrams/`](architecture/diagrams/) (Mermaid).

## Operazioni (runbook)

- [Sviluppo locale production-like](runbooks/local-development.md) — avvio e uso dello stack.
- [Pipeline documentale](runbooks/document-pipeline.md) — flusso Co-Pilot end-to-end.
- [Osservabilità](runbooks/observability.md) — metriche, trace, log, dashboard, alert.
- [DLQ e recovery](runbooks/dlq-recovery.md) — gestione job falliti e ripristino.
- [Backup/restore locale](runbooks/backup-restore-local.md) — PostgreSQL.
- [CI/CD](runbooks/ci-cd.md) — pipeline e quality gate.
- [Permessi AWS necessari](runbooks/aws-permissions-needed.md) — per il passaggio ad AWS reale.
- [Matrice di verifica](operations/verify.md) — come validare il comportamento atteso.

## Sicurezza

- [Confine di autenticazione/autorizzazione](security/auth-boundary.md) — identità, RBAC/ABAC.
- [Matrice permessi IAM](security/iam-permissions-matrix.md) — minimo privilegio sulle risorse.
- [OWASP ASVS Mapping](security/owasp-asvs-mapping.md) — aderenza ai controlli ASVS.

## Riferimenti esterni alla cartella `docs/`

- [`openapi/v1/`](../openapi/v1/) — contratto API OpenAPI (fonte del client generato).
- [`infra/localstack/`](../infra/localstack/) — Terraform e risorse AWS-like locali.
- [`docker/`](../docker/) — configurazioni runtime, edge e osservabilità.
- [`.github/workflows/`](../.github/workflows/) — pipeline CI e quality gate.

## Terminologia

Per evitare ambiguità ricorrenti, in tutta la documentazione i termini hanno questo significato:

- **Capitolato** — il documento `[NEXUM] BRD-FASE02-2025` (Business Requirements Document, C5).
  È il **documento di riferimento per i requisiti di business**.
- **ADR** — *Architecture Decision Record*: una decisione architetturale registrata in
  [`architecture-decisions/`](architecture-decisions/README.md). **Non** indica «Analisi dei
  Requisiti»; i requisiti stanno nel Capitolato.
- **PoC** — questa Proof of Concept: ambiente locale e riproducibile che emula i servizi AWS
  tramite LocalStack, non un deploy di produzione.
- **AWS-like / emulato** — servizio AWS riprodotto in locale via LocalStack (es. SQS, S3, KMS,
  Step Functions); stesso modello di interazione, infrastruttura non gestita.
