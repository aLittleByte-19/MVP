<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Support\WorkflowContext;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;

/**
 * Applicazione: avvia l'esecuzione Step Functions della pipeline documentale
 * per un documento gia' persistito. Sostituisce DocumentWorkflowService:
 * stessa logica (guard di configurazione, idempotenza, tracciamento
 * fallimenti), orchestrata attraverso le porte del dominio invece che
 * Eloquent/SfnClient diretti.
 */
class StartDocumentWorkflowService implements StartDocumentWorkflowUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly WorkflowEnginePort $workflowEngine,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
        private readonly WorkflowContext $context,
        private readonly ClockInterface $clock,
    ) {}

    public function start(int $documentId, ?string $correlationId, ?string $requestId): void
    {
        $document = $this->documents->findOriginalDocument($documentId);

        if ($document->workflowExecutionArn !== null && $document->processingStatus === ProcessingStatus::Processing->value) {
            return;
        }

        $this->context->bind($requestId, $correlationId, $document->tenantId);

        $stateMachineArn = (string) config('services.workflow.state_machine_arn');
        $taskQueueUrl = $this->taskQueueUrl();

        if ($stateMachineArn === '' || $taskQueueUrl === '') {
            throw new \RuntimeException('Workflow documentale non configurato: DOCUMENT_PIPELINE_STATE_MACHINE_ARN e DOCUMENT_PIPELINE_TASK_QUEUE_URL sono obbligatori.');
        }

        // Real Textract can only read objects from real S3. If OCR is enabled while
        // documents live on the LocalStack disk, Textract fails with a cryptic
        // InvalidS3ObjectException, so fail fast with an actionable message instead.
        if ((bool) config('services.textract.enabled') && config('mvp.documents.storage_disk') !== 'real_s3') {
            throw new \RuntimeException('Textract è abilitato (TEXTRACT_ENABLED=true) ma MVP_DOCUMENT_DISK non è "real_s3": i documenti restano su S3 LocalStack e Textract reale non può leggerli. Imposta MVP_DOCUMENT_DISK=real_s3 ed esegui "make refresh-runtime".');
        }

        $bucket = $document->s3Bucket ?: $this->documentBucket();
        $key = $document->s3Key ?: $this->documentKey($document->filePath);

        $input = [
            'document_id' => $documentId,
            'tenant_id' => $document->tenantId,
            'correlation_id' => $this->context->correlationId() ?? (string) Str::uuid(),
            'request_id' => $this->context->requestId() ?? (string) Str::uuid(),
            's3_bucket' => $bucket,
            's3_key' => $key,
            'task_queue_url' => $taskQueueUrl,
            'metadata' => ['valid' => true],
        ];

        try {
            $executionArn = $this->workflowEngine->startExecution($stateMachineArn, $this->executionName($documentId), $input);

            $this->documents->updateOriginalDocument($documentId, [
                'processing_status' => ProcessingStatus::Processing,
                'workflow_execution_arn' => $executionArn,
                'workflow_started_at' => $this->clock->now(),
                'workflow_completed_at' => null,
                'workflow_failed_at' => null,
                'workflow_failure_reason' => null,
                's3_bucket' => $bucket,
                's3_key' => $key,
                'error_message' => null,
            ]);

            $this->audit->record(
                'mvp-document-workflow-started',
                null,
                'original_document',
                (string) $documentId,
                [
                    'execution_arn' => $executionArn,
                    'state_machine_arn' => $stateMachineArn,
                    'task_queue_url' => $taskQueueUrl,
                ],
                tenantId: $document->tenantId,
            );
            $this->metrics->recordDomainCounter('stepfunctions_executions_started_total', [
                'state_machine' => $this->shortName($stateMachineArn),
            ]);
        } catch (\Throwable $e) {
            $this->documents->updateOriginalDocument($documentId, [
                'processing_status' => ProcessingStatus::Failed,
                'workflow_failed_at' => $this->clock->now(),
                'workflow_failure_reason' => $e->getMessage(),
                'error_message' => 'Avvio workflow documentale non disponibile.',
            ]);
            $this->audit->record(
                'mvp-document-workflow-start-failed',
                null,
                'original_document',
                (string) $documentId,
                ['message' => $e->getMessage()],
                tenantId: $document->tenantId,
            );
            $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', [
                'state_machine' => $this->shortName($stateMachineArn),
            ]);

            // WorkflowEnginePort traduce gia' gli errori AWS in un RuntimeException
            // con messaggio azionabile: qui si rilancia senza ri-tradurlo.
            throw $e;
        }
    }

    private function executionName(int $documentId): string
    {
        return 'mvp-doc-'.$documentId.'-'.Str::uuid();
    }

    private function taskQueueUrl(): string
    {
        $configured = (string) config('services.workflow.task_queue_url');

        if ($configured !== '') {
            return $configured;
        }

        $prefix = rtrim((string) config('queue.connections.sqs.prefix'), '/');
        $queue = (string) config('queue.connections.sqs.queue');

        return $prefix !== '' && $queue !== '' ? "{$prefix}/{$queue}" : '';
    }

    private function documentBucket(): string
    {
        $disk = (string) config('mvp.documents.storage_disk', config('filesystems.default'));

        return (string) config("filesystems.disks.{$disk}.bucket", config('services.textract.s3_bucket'));
    }

    private function documentKey(string $filePath): string
    {
        $disk = (string) config('mvp.documents.storage_disk', config('filesystems.default'));
        $root = trim((string) config("filesystems.disks.{$disk}.root", ''), '/');

        return $root === '' ? $filePath : $root.'/'.$filePath;
    }

    private function shortName(string $arn): string
    {
        return Str::of($arn)->afterLast(':')->toString() ?: 'unknown';
    }
}
