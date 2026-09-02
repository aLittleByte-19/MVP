<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStarted;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStartFailed;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use App\Mvp\Support\Persistence\TransactionManagerPort;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Support\StateMachineName;
use App\Mvp\Workflow\Support\WorkflowContext;
use Psr\Clock\ClockInterface;

/**
 * Applicazione: avvia l'esecuzione Step Functions della pipeline documentale
 * per un documento gia' persistito.
 *
 * I parametri di configurazione (ARN, code, flag Textract, disco/bucket
 * documentale) sono risolti una volta nel service provider e passati al
 * costruttore, invece di leggere `config()` qui (ADR 0010).
 *
 * `WorkflowContext` e' una classe pura: `start()` e' istanziabile ed
 * eseguibile in un test Pest puro (vedi StartDocumentWorkflowServiceTest).
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
        private readonly TransactionManagerPort $transactions,
        private readonly string $stateMachineArn,
        private readonly string $taskQueueUrl,
        private readonly bool $textractEnabled,
        private readonly string $storageDisk,
        private readonly string $documentBucketFallback,
        private readonly string $documentKeyPrefix,
    ) {}

    /**
     * Stesso lock pessimistico di StartCommunicationWorkflowService::start()
     * e per lo stesso motivo: senza, due richieste quasi simultanee potrebbero
     * superare entrambe il controllo "e' gia' in corso?" e avviare due
     * esecuzioni Step Functions per lo stesso documento.
     */
    public function start(int $documentId, ?string $correlationId, ?string $requestId): void
    {
        $startedEvent = null;
        $failedEvent = null;
        $failure = null;

        $this->transactions->run(function () use ($documentId, $correlationId, $requestId, &$startedEvent, &$failedEvent, &$failure): void {
            $document = $this->documents->findOriginalDocumentForUpdate($documentId);

            if ($document->workflowExecutionArn() !== null && $document->processingStatus() === ProcessingStatus::Processing) {
                return;
            }

            $this->context->bind($requestId, $correlationId, $document->tenantId);

            $stateMachineArn = $this->stateMachineArn;
            $taskQueueUrl = $this->taskQueueUrl;
            $bucket = $document->s3Bucket() ?: $this->documentBucketFallback;
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
                if ($stateMachineArn === '' || $taskQueueUrl === '') {
                    throw new \RuntimeException('Workflow documentale non configurato: DOCUMENT_PIPELINE_STATE_MACHINE_ARN e DOCUMENT_PIPELINE_TASK_QUEUE_URL sono obbligatori.');
                }

                // Real Textract can only read objects from real S3. If OCR is enabled while
                // documents live on the LocalStack disk, Textract fails with a cryptic
                // InvalidS3ObjectException, so fail fast with an actionable message instead.
                if ($this->textractEnabled && $this->storageDisk !== 'real_s3') {
                    throw new \RuntimeException('Textract è abilitato (TEXTRACT_ENABLED=true) ma MVP_DOCUMENT_DISK non è "real_s3": i documenti restano su S3 LocalStack e Textract reale non può leggerli. Imposta MVP_DOCUMENT_DISK=real_s3 ed esegui "make refresh-runtime".');
                }

                $executionArn = $this->workflowEngine->startExecution($stateMachineArn, $this->executionName($documentId), $input);

                $document->startProcessing($executionArn, $bucket, $key, $this->clock->now());
                $this->documents->saveOriginalDocument($document);

                $startedEvent = new DocumentWorkflowStarted(
                    $documentId,
                    $document->tenantId,
                    $executionArn,
                    $stateMachineArn,
                    StateMachineName::fromArn($stateMachineArn),
                    $taskQueueUrl,
                );
            } catch (\Throwable $e) {
                $document->fail('Avvio workflow documentale non disponibile.', $e->getMessage(), $this->clock->now());
                $this->documents->saveOriginalDocument($document);

                $failedEvent = new DocumentWorkflowStartFailed(
                    $documentId,
                    $document->tenantId,
                    $e->getMessage(),
                    StateMachineName::fromArn($stateMachineArn),
                );
                $failure = $e;
            }
        });

        if ($startedEvent !== null) {
            $this->events->dispatch($startedEvent);

            return;
        }

        if ($failedEvent !== null && $failure !== null) {
            $this->events->dispatch($failedEvent);

            // WorkflowEnginePort traduce gia' gli errori AWS in un RuntimeException
            // con messaggio azionabile: qui si rilancia senza ri-tradurlo.
            throw $failure;
        }
    }

    private function executionName(int $documentId): string
    {
        return 'mvp-doc-'.$documentId.'-'.$this->ids->generate();
    }

    private function documentKey(string $filePath): string
    {
        return $this->documentKeyPrefix === '' ? $filePath : $this->documentKeyPrefix.'/'.$filePath;
    }
}
