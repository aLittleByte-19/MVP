<?php

namespace App\Copilot\Workflow\Services;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Documents\Services\DocumentProcessingService;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Ocr\Services\TextractService;
use App\Models\Copilot\DocumentWorkflowTask;
use App\Models\Copilot\OriginalDocument;
use Illuminate\Support\Facades\Log;

class DocumentWorkflowTaskHandler
{
    public function __construct(
        private readonly DocumentProcessingService $documents,
        private readonly TextractService $textract,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array{callback_required: bool, output: array<string, mixed>}
     */
    public function handle(array $message): array
    {
        $taskToken = (string) ($message['taskToken'] ?? $message['task_token'] ?? '');
        $taskType = (string) ($message['taskType'] ?? $message['task_type'] ?? '');
        $documentId = (int) ($message['documentId'] ?? $message['document_id'] ?? 0);

        if ($taskToken === '' || $taskType === '' || $documentId <= 0) {
            throw new \InvalidArgumentException('Messaggio workflow non valido: taskToken, taskType e documentId sono obbligatori.');
        }

        $tokenHash = hash('sha256', $taskToken);
        $document = OriginalDocument::query()->findOrFail($documentId);

        $task = DocumentWorkflowTask::query()->firstOrCreate(
            ['task_token_hash' => $tokenHash],
            [
                'original_document_id' => $document->id,
                'task_type' => $taskType,
                'status' => 'pending',
                'input_payload' => $this->redactTaskToken($message),
            ],
        );

        if (in_array($task->status, ['succeeded', 'skipped'], true)) {
            return [
                'callback_required' => false,
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => $task->status,
                        'idempotent' => true,
                    ],
                ]),
            ];
        }

        if (! $this->claim($task)) {
            // Consegna duplicata mentre un altro worker sta gia' elaborando lo
            // stesso token: nessun callback, il worker attivo completera' il task.
            $this->metrics->recordDomainCounter('sqs_messages_duplicate_total', ['task_type' => $taskType]);

            return [
                'callback_required' => false,
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => 'running',
                        'duplicate_in_flight' => true,
                    ],
                ]),
            ];
        }
        $this->metrics->recordDomainCounter('sqs_messages_received_total', ['task_type' => $taskType]);

        try {
            $result = $this->executeTask($taskType, $document->refresh(), $message);
            $status = ($result['skipped'] ?? false) ? 'skipped' : 'succeeded';
            $output = array_merge($this->baseOutput($message), [
                'task_result' => array_merge($result, [
                    'task_type' => $taskType,
                    'status' => $status,
                ]),
            ]);

            $task->update([
                'status' => $status,
                'output_payload' => $output['task_result'],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                'poc-document-workflow-task-'.$status,
                resourceType: 'original_document',
                resourceId: (string) $document->id,
                metadata: ['task_type' => $taskType],
                tenantId: $document->tenant_id,
            );

            return ['callback_required' => true, 'output' => $output];
        } catch (\Throwable $e) {
            $document->refresh();
            $userMessage = $document->error_message ?: $e->getMessage();

            $task->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            $document->update([
                'processing_status' => ProcessingStatus::Failed,
                'workflow_failed_at' => now(),
                'workflow_failure_reason' => $e->getMessage(),
                'error_message' => $userMessage,
            ]);
            $this->metrics->recordDomainCounter('sqs_messages_failed_total', ['task_type' => $taskType]);
            $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', ['reason' => 'task_failure']);
            Log::error('Document workflow task failed', [
                'document_id' => $document->id,
                'task_type' => $taskType,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Claim atomico del task: con piu' worker solo uno puo' portare lo stato a
     * running. Un task gia' running viene riconquistato solo se stale (worker
     * morto oltre il visibility timeout SQS).
     */
    private function claim(DocumentWorkflowTask $task): bool
    {
        $staleBefore = now()->subSeconds(max(60, (int) config('poc.workflow.running_claim_ttl_seconds', 900)));

        $claimed = DocumentWorkflowTask::query()
            ->whereKey($task->id)
            ->where(function ($query) use ($staleBefore) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($running) use ($staleBefore) {
                        $running->where('status', 'running')
                            ->where(function ($stale) use ($staleBefore) {
                                $stale->whereNull('started_at')->orWhere('started_at', '<', $staleBefore);
                            });
                    });
            })
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'error_message' => null,
            ]);

        if ($claimed === 1) {
            $task->refresh();

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function executeTask(string $taskType, OriginalDocument $document, array $message): array
    {
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

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function baseOutput(array $message): array
    {
        return collect($message)
            ->except(['taskToken', 'task_token'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function redactTaskToken(array $message): array
    {
        return array_merge($this->baseOutput($message), [
            'task_token_present' => isset($message['taskToken']) || isset($message['task_token']),
        ]);
    }
}
