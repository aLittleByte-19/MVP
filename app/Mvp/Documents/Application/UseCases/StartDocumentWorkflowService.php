<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStarted;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStartFailed;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
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
        private readonly DocumentEventDispatcherPort $events,
        private readonly WorkflowContext $context,
        private readonly ClockInterface $clock,
        private readonly UniqueIdGeneratorPort $ids,
    ) {}

    public function start(int $documentId, ?string $correlationId, ?string $requestId): void
    {
        $document = $this->documents->findOriginalDocument($documentId);

        if ($document->workflowExecutionArn() !== null && $document->processingStatus() === ProcessingStatus::Processing) {
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

        $bucket = $document->s3Bucket() ?: $this->documentBucket();
        $key = $document->s3Key() ?: $this->documentKey($document->filePath);

        $input = [
            'document_id' => $documentId,
            'tenant_id' => $document->tenantId,
            'correlation_id' => $this->context->correlationId() ?? $this->ids->generate(),
            'request_id' => $this->context->requestId() ?? $this->ids->generate(),
            's3_bucket' => $bucket,
            's3_key' => $key,
            'task_queue_url' => $taskQueueUrl,
            'metadata' => ['valid' => true],
        ];

        try {
            $executionArn = $this->workflowEngine->startExecution($stateMachineArn, $this->executionName($documentId), $input);

            $document->startProcessing($executionArn, $bucket, $key, $this->clock->now());
            $this->documents->saveOriginalDocument($document);

            $this->events->dispatch(new DocumentWorkflowStarted(
                $documentId,
                $document->tenantId,
                $executionArn,
                $stateMachineArn,
                $this->shortName($stateMachineArn),
                $taskQueueUrl,
            ));
        } catch (\Throwable $e) {
            $document->fail('Avvio workflow documentale non disponibile.', $e->getMessage(), $this->clock->now());
            $this->documents->saveOriginalDocument($document);
            $this->events->dispatch(new DocumentWorkflowStartFailed(
                $documentId,
                $document->tenantId,
                $e->getMessage(),
                $this->shortName($stateMachineArn),
            ));

            // WorkflowEnginePort traduce gia' gli errori AWS in un RuntimeException
            // con messaggio azionabile: qui si rilancia senza ri-tradurlo.
            throw $e;
        }
    }

    private function executionName(int $documentId): string
    {
        return 'mvp-doc-'.$documentId.'-'.$this->ids->generate();
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
