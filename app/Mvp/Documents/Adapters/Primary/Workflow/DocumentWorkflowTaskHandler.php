<?php

namespace App\Mvp\Documents\Adapters\Primary\Workflow;

use App\Mvp\Documents\Domain\Ports\Inbound\FinalizeDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Workflow\Contracts\WorkflowSubject;
use App\Mvp\Workflow\Contracts\WorkflowTaskHandler;
use Psr\Clock\ClockInterface;

/**
 * Adapter primario guidato da Step Functions/SQS (invece che da HTTP):
 * traduce ogni task di callback nella chiamata al caso d'uso corrispondente,
 * e il risultato nella forma che il runner si aspetta. Nessuna regola di
 * business qui: l'idempotenza verso la ridelivery ("documento gia'
 * completato") vive nei singoli casi d'uso, non in questo adapter. Passa al
 * runner solo {@see WorkflowSubject} (id + tenant), che resta cosi'
 * domain-agnostic.
 */
class DocumentWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly RunOcrUseCase $runOcr,
        private readonly ProcessDocumentUseCase $processDocument,
        private readonly FinalizeDocumentWorkflowUseCase $finalize,
        private readonly DocumentRepository $documents,
        private readonly ClockInterface $clock,
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

    public function pipeline(): string
    {
        return 'documents';
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

        // Difesa in profondita': i messaggi arrivano dalla pipeline stessa, non
        // da input utente diretto, ma un tenantId che non corrisponde al
        // documento e' un segnale di messaggio corrotto da non eseguire.
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
        $document = $this->documents->findOriginalDocument($subject->id);

        // Fallback per i task che falliscono prima che il caso d'uso scriva
        // un messaggio comprensibile (vedi RunOcrService/ProcessDocumentService).
        $document->fail($document->errorMessage() ?: $e->getMessage(), $e->getMessage(), $this->clock->now());
        $this->documents->saveOriginalDocument($document);
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
