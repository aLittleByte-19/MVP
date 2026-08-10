<?php

namespace App\Mvp\Documents\Adapters\Primary\Workflow;

use App\Models\OriginalDocument;
use App\Mvp\Documents\Domain\Ports\Inbound\FinalizeDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Workflow\Contracts\WorkflowTaskHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Adapter primario guidato da Step Functions/SQS (invece che da HTTP):
 * traduce ogni task di callback nella chiamata al caso d'uso corrispondente
 * tramite la sua porta primaria. Nessuna regola di business qui — solo
 * dispatch per tipo di task e traduzione del risultato nella forma che il
 * runner si aspetta. Implementa il contratto condiviso WorkflowTaskHandler
 * (infrastruttura di Workflow, fuori dal perimetro esagonale: vedi ADR 0010),
 * quindi puo' legittimamente risolvere/aggiornare l'aggregato Eloquent per le
 * proprie responsabilita' di adapter (resolveSubject, onFailure) — le
 * decisioni di dominio restano nei casi d'uso iniettati.
 */
class DocumentWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly RunOcrUseCase $runOcr,
        private readonly ProcessDocumentUseCase $processDocument,
        private readonly FinalizeDocumentWorkflowUseCase $finalize,
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
    public function resolveSubject(array $message): Model
    {
        $documentId = (int) ($message['documentId'] ?? $message['document_id'] ?? 0);

        if ($documentId <= 0) {
            throw new \InvalidArgumentException('Messaggio workflow non valido: documentId e\' obbligatorio.');
        }

        return OriginalDocument::query()->findOrFail($documentId);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function execute(string $taskType, Model $subject, array $message): array
    {
        $document = $this->asDocument($subject);

        if ($document->processing_status === ProcessingStatus::Completed && $taskType !== 'dispatch.domain_event') {
            return ['skipped' => true, 'reason' => 'document_already_completed'];
        }

        return match ($taskType) {
            'textract.ocr' => $this->runOcrStep($document, $message),
            'bedrock.extract' => $this->processDocumentStep($document),
            'persist.results' => ['skipped' => false, 'processing_status' => $this->finalize->currentStatus($document->id)],
            'dispatch.domain_event' => array_merge(['skipped' => false], $this->finalize->dispatchCompletionEvent($document->id)),
            default => throw new \InvalidArgumentException("Task workflow non supportato: {$taskType}"),
        };
    }

    public function onFailure(Model $subject, string $taskType, \Throwable $e): void
    {
        $document = $this->asDocument($subject);

        $document->update([
            'processing_status' => ProcessingStatus::Failed,
            'workflow_failed_at' => now(),
            'workflow_failure_reason' => $e->getMessage(),
            // Il caso d'uso ha gia' scritto un messaggio comprensibile per
            // l'operatore: quello tecnico resta in workflow_failure_reason.
            'error_message' => $document->error_message ?: $e->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function runOcrStep(OriginalDocument $document, array $message): array
    {
        $bucket = $message['s3Bucket'] ?? $message['s3_bucket'] ?? null;
        $key = $message['s3Key'] ?? $message['s3_key'] ?? null;

        $result = $this->runOcr->run($document->id, $bucket !== null ? (string) $bucket : null, $key !== null ? (string) $key : null);

        return [
            'skipped' => $result['skipped'],
            'textract_job_id' => $result['jobId'],
            'ocr_confidence_avg' => $result['confidenceAvg'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processDocumentStep(OriginalDocument $document): array
    {
        $this->processDocument->process($document->id);

        return [
            'skipped' => false,
            'sub_documents' => $document->refresh()->subDocuments()->count(),
        ];
    }

    private function asDocument(Model $subject): OriginalDocument
    {
        if (! $subject instanceof OriginalDocument) {
            throw new \InvalidArgumentException('Il task documentale richiede un OriginalDocument.');
        }

        return $subject;
    }
}
