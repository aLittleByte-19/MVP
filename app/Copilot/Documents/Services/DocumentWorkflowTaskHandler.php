<?php

namespace App\Copilot\Documents\Services;

use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Ocr\Services\TextractService;
use App\Copilot\Workflow\Contracts\WorkflowTaskHandler;
use App\Models\Copilot\OriginalDocument;
use Illuminate\Database\Eloquent\Model;

class DocumentWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly DocumentProcessingService $documents,
        private readonly TextractService $textract,
        private readonly MetricsRecorder $metrics,
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
            'textract.ocr' => $this->runTextract($document, $message),
            'bedrock.extract' => $this->runBedrockExtraction($document),
            'persist.results' => $this->persistResults($document),
            'dispatch.domain_event' => $this->dispatchDomainEvent($document),
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
            // Il service di dominio ha gia' scritto un messaggio comprensibile
            // per l'operatore: quello tecnico resta in workflow_failure_reason.
            'error_message' => $document->error_message ?: $e->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function runTextract(OriginalDocument $document, array $message): array
    {
        $bucket = (string) ($message['s3Bucket'] ?? $message['s3_bucket'] ?? $document->s3_bucket ?? '');
        $key = (string) ($message['s3Key'] ?? $message['s3_key'] ?? $document->s3_key ?? '');
        $result = $this->textract->detectText($bucket, $key, $document);

        return [
            'skipped' => ! $result['enabled'],
            'textract_job_id' => $result['job_id'],
            'ocr_confidence_avg' => $result['confidence_avg'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runBedrockExtraction(OriginalDocument $document): array
    {
        $this->documents->process($document->refresh());

        return [
            'skipped' => false,
            'sub_documents' => $document->refresh()->subDocuments()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistResults(OriginalDocument $document): array
    {
        $document->refresh();
        $status = $document->processing_status;

        return [
            'skipped' => false,
            'processing_status' => $status instanceof ProcessingStatus ? $status->value : (string) $status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchDomainEvent(OriginalDocument $document): array
    {
        if ($document->processing_status === ProcessingStatus::Completed && $document->workflow_completed_at === null) {
            $document->update(['workflow_completed_at' => now()]);
        }

        $this->metrics->recordDomainCounter('document_workflow_completed_total');

        return [
            'skipped' => false,
            'event' => $document->processing_status === ProcessingStatus::Completed
                ? 'DocumentPipelineCompleted'
                : 'DocumentPipelineProgressed',
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
