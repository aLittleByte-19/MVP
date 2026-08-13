<?php

namespace App\Mvp\Documents\Adapters\Primary\Workflow;

use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Ports\Inbound\FinalizeDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Workflow\Contracts\WorkflowSubject;
use App\Mvp\Workflow\Contracts\WorkflowTaskHandler;

/**
 * Adapter primario guidato da Step Functions/SQS (invece che da HTTP):
 * traduce ogni task di callback nella chiamata al caso d'uso corrispondente
 * tramite la sua porta primaria, e traduce il risultato nella forma che il
 * runner si aspetta. Nessuna regola di business qui: l'idempotenza verso la
 * ridelivery del task ("documento gia' completato") vive nei singoli casi
 * d'uso (RunOcrService, ProcessDocumentService), non in questo adapter —
 * ciascuno la segnala nel proprio valore di ritorno invece che con una
 * guardia centralizzata qui. Risolve e aggiorna l'aggregato documentale
 * attraverso {@see DocumentRepository} (la stessa porta secondaria usata dai
 * casi d'uso), non attraverso Eloquent diretto: implementa il contratto
 * condiviso WorkflowTaskHandler passando solo {@see WorkflowSubject} (id +
 * tenant) al runner, che resta cosi' domain-agnostic.
 */
class DocumentWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly RunOcrUseCase $runOcr,
        private readonly ProcessDocumentUseCase $processDocument,
        private readonly FinalizeDocumentWorkflowUseCase $finalize,
        private readonly DocumentRepository $documents,
    ) {}

    /**
     * @return array<int, string>
     */
    public function taskTypes(): array
    {
        return ['textract.ocr', 'bedrock.extract', 'persist.results', 'dispatch.domain_event'];
    }

    public function subjectType(): string
    {
        return 'original_document';
    }

    public function auditEventPrefix(): string
    {
        return 'mvp-document-workflow-task-';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function resolveSubject(array $message): WorkflowSubject
    {
        $documentId = (int) ($message['documentId'] ?? $message['document_id'] ?? 0);

        if ($documentId <= 0) {
            throw new \InvalidArgumentException('Messaggio workflow non valido: documentId e\' obbligatorio.');
        }

        $document = $this->documents->findOriginalDocument($documentId);

        // Difesa in profondita': l'autorizzazione vera vive al bordo HTTP
        // (i messaggi di workflow sono generati dalla pipeline stessa, non
        // da input utente diretto), ma se il tenantId nel messaggio non
        // corrisponde a quello del documento e' un segnale di messaggio
        // corrotto o malformato che non va eseguito silenziosamente.
        $tenantId = $message['tenantId'] ?? $message['tenant_id'] ?? null;

        if ($tenantId !== null && $document->tenantId !== $tenantId) {
            throw new \InvalidArgumentException("Messaggio workflow non valido: tenantId non corrisponde al documento {$documentId}.");
        }

        return new WorkflowSubject($document->id, $document->tenantId);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function execute(string $taskType, WorkflowSubject $subject, array $message): array
    {
        return match ($taskType) {
            'textract.ocr' => $this->runOcrStep($subject->id, $message),
            'bedrock.extract' => $this->processDocumentStep($subject->id),
            'persist.results' => ['skipped' => false, 'processing_status' => $this->finalize->currentStatus($subject->id)],
            'dispatch.domain_event' => array_merge(['skipped' => false], $this->finalize->dispatchCompletionEvent($subject->id)),
            default => throw new \InvalidArgumentException("Task workflow non supportato: {$taskType}"),
        };
    }

    public function onFailure(WorkflowSubject $subject, string $taskType, \Throwable $e): void
    {
        $current = $this->documents->findOriginalDocument($subject->id);

        $this->documents->updateOriginalDocument($subject->id, OriginalDocumentChanges::none()
            ->withProcessingStatus(ProcessingStatus::Failed)
            ->withWorkflowFailedAt(new \DateTimeImmutable)
            ->withWorkflowFailureReason($e->getMessage())
            // Il caso d'uso ha gia' scritto un messaggio comprensibile per
            // l'operatore: quello tecnico resta in workflowFailureReason.
            ->withErrorMessage($current->errorMessage ?: $e->getMessage()));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function runOcrStep(int $documentId, array $message): array
    {
        $bucket = $message['s3Bucket'] ?? $message['s3_bucket'] ?? null;
        $key = $message['s3Key'] ?? $message['s3_key'] ?? null;

        $result = $this->runOcr->run($documentId, $bucket !== null ? (string) $bucket : null, $key !== null ? (string) $key : null);

        return [
            'skipped' => $result['skipped'],
            'textract_job_id' => $result['jobId'],
            'ocr_confidence_avg' => $result['confidenceAvg'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processDocumentStep(int $documentId): array
    {
        $result = $this->processDocument->process($documentId);

        if ($result['skipped']) {
            return ['skipped' => true, 'reason' => 'document_already_completed'];
        }

        return [
            'skipped' => false,
            'sub_documents' => $this->documents->countSubDocuments($documentId),
        ];
    }
}
