# ADR 0005 — Nessun fallback automatico dei servizi AI

Status: Accepted, implemented
Date: 2026-06-08

## Context

Generazione comunicazioni, split documentale ed estrazione campi dipendono dai servizi AI
configurati (Bedrock, opzionalmente Textract). Il runtime non deve nascondere i fallimenti di
servizio, modello o permessi producendo dati sostitutivi: un risultato silenziosamente
sintetico sarebbe indistinguibile da uno reale e minerebbe la fiducia nei dati estratti.

## Decision

Non usare fallback automatici nei flussi applicativi principali. Se un servizio core richiesto
dalla pipeline fallisce o non è disponibile, la pipeline deve passare a uno stato `failed`
esplicito e registrare contesto strutturato non sensibile.

Mock e fake sono ammessi solo nei test unitari isolati. I flussi end-to-end della PoC non devono
sostituire silenziosamente i servizi AWS reali con implementazioni fittizie: la "simulazione"
vive nell'infrastruttura (LocalStack), non in branch condizionali del codice applicativo.

## Consequences

- Lo sviluppo locale richiede LocalStack e una configurazione chiara (fail-fast su config
  incoerenti).
- I test usano fake unitari separati e percorsi di integrazione/smoke distinti.
- Gli errori esposti all'utente devono essere chiari senza trapelare credenziali, prompt,
  contenuto dei documenti o altri dati sensibili.
- Il classificatore documentale restituisce sempre almeno un destinatario: quando non distingue
  destinatari multipli, l'intero documento è trattato come un singolo destinatario. È un esito di
  rilevamento valido (non un dato sintetico sostitutivo di un servizio fallito), quindi non
  contraddice questa decisione; un fallimento reale di Bedrock/Textract resta uno stato `failed`.

## Alternatives considered

- **Fallback a un modello/dato di default su errore AI**: scartato perché maschera i guasti e
  produce output non affidabili senza segnalazione.
- **Mock applicativi attivabili da flag in produzione**: scartati perché diluiscono la differenza
  tra demo e produzione e lasciano codice fittizio nei percorsi reali.

## Implementation evidence

- Stato `failed` esplicito e `workflow_failure_reason` in `app/Copilot/Workflow/Services/DocumentWorkflowService.php`.
- Errori Bedrock → `AiServiceException` → 502 in `app/Copilot/Ai/BedrockService.php`.
- Guard fail-fast Textract/`real_s3` in `DocumentWorkflowService::start()`; assert chiavi
  obbligatorie in `app/Copilot/Support/RuntimeConfigurationLoader.php`.
- ASL: rami `Catch` → stato `Failed` in `infra/localstack/state-machines/document-pipeline.asl.json`.
- Alert `BedrockFailureRateHigh`, `TextractFailureRateHigh`, `StepFunctionExecutionFailed`.

## References

- AWS Well-Architected — reliability pillar: https://docs.aws.amazon.com/wellarchitected/latest/reliability-pillar/welcome.html
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/

## Related documents

- [`0003-sqs-instead-of-redis-queue.md`](0003-sqs-instead-of-redis-queue.md)
- [`0006-observability-and-audit.md`](0006-observability-and-audit.md)
- [`../IMPLEMENTATION_OVERVIEW.md`](../IMPLEMENTATION_OVERVIEW.md) (§10)
