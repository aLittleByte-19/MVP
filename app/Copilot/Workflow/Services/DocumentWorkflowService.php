<?php

namespace App\Copilot\Workflow\Services;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Identity\PocUser;
use App\Copilot\Observability\MetricsRecorder;
use App\Models\Copilot\OriginalDocument;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentWorkflowService
{
    public function __construct(
        private readonly SfnClient $stepFunctions,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function start(OriginalDocument $document, ?PocUser $actor = null, ?Request $request = null): OriginalDocument
    {
        if ($document->workflow_execution_arn && $document->processing_status === ProcessingStatus::Processing) {
            return $document;
        }

        $stateMachineArn = (string) config('services.workflow.state_machine_arn');
        $taskQueueUrl = $this->taskQueueUrl();

        if ($stateMachineArn === '' || $taskQueueUrl === '') {
            throw new \RuntimeException('Workflow documentale non configurato: DOCUMENT_PIPELINE_STATE_MACHINE_ARN e DOCUMENT_PIPELINE_TASK_QUEUE_URL sono obbligatori.');
        }

        // Real Textract can only read objects from real S3. If OCR is enabled while
        // documents live on the LocalStack disk, Textract fails with a cryptic
        // InvalidS3ObjectException, so fail fast with an actionable message instead.
        if ((bool) config('services.textract.enabled') && config('poc.documents.storage_disk') !== 'real_s3') {
            throw new \RuntimeException('Textract è abilitato (TEXTRACT_ENABLED=true) ma POC_DOCUMENT_DISK non è "real_s3": i documenti restano su S3 LocalStack e Textract reale non può leggerli. Imposta POC_DOCUMENT_DISK=real_s3 ed esegui "make refresh-runtime".');
        }

        $input = $this->workflowInput($document, $taskQueueUrl, $request);

        try {
            $result = $this->stepFunctions->startExecution([
                'stateMachineArn' => $stateMachineArn,
                'name' => $this->executionName($document),
                'input' => json_encode($input, JSON_THROW_ON_ERROR),
            ]);

            $executionArn = (string) $result->get('executionArn');
            $document->update([
                'processing_status' => ProcessingStatus::Processing,
                'workflow_execution_arn' => $executionArn,
                'workflow_started_at' => now(),
                'workflow_completed_at' => null,
                'workflow_failed_at' => null,
                'workflow_failure_reason' => null,
                's3_bucket' => $input['s3_bucket'],
                's3_key' => $input['s3_key'],
                'error_message' => null,
            ]);

            $this->audit->record(
                'poc-document-workflow-started',
                $actor,
                'original_document',
                (string) $document->id,
                [
                    'execution_arn' => $executionArn,
                    'state_machine_arn' => $stateMachineArn,
                    'task_queue_url' => $taskQueueUrl,
                ],
                $request,
                $document->tenant_id,
            );
            $this->metrics->recordDomainCounter('stepfunctions_executions_started_total', [
                'state_machine' => $this->shortName($stateMachineArn),
            ]);

            return $document->refresh();
        } catch (AwsException $e) {
            $this->markStartFailure($document, $e, $actor, $request);

            throw new \RuntimeException('Impossibile avviare la pipeline Step Functions: '.$e->getAwsErrorMessage(), previous: $e);
        } catch (\Throwable $e) {
            $this->markStartFailure($document, $e, $actor, $request);

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowInput(OriginalDocument $document, string $taskQueueUrl, ?Request $request): array
    {
        return [
            'document_id' => $document->id,
            'tenant_id' => $document->tenant_id,
            'correlation_id' => (string) ($request?->attributes->get('correlation_id') ?: Str::uuid()),
            'request_id' => (string) ($request?->attributes->get('request_id') ?: Str::uuid()),
            's3_bucket' => $this->documentBucket($document),
            's3_key' => $this->documentKey($document),
            'task_queue_url' => $taskQueueUrl,
            'metadata' => [
                'valid' => true,
                'filename' => $document->original_filename,
            ],
        ];
    }

    private function markStartFailure(OriginalDocument $document, \Throwable $e, ?PocUser $actor, ?Request $request): void
    {
        Log::error('Document workflow start failed', [
            'document_id' => $document->id,
            'message' => $e->getMessage(),
        ]);

        $document->update([
            'processing_status' => ProcessingStatus::Failed,
            'workflow_failed_at' => now(),
            'workflow_failure_reason' => $e->getMessage(),
            'error_message' => 'Avvio workflow documentale non disponibile.',
        ]);
        $this->audit->record(
            'poc-document-workflow-start-failed',
            $actor,
            'original_document',
            (string) $document->id,
            ['message' => $e->getMessage()],
            $request,
            $document->tenant_id,
        );
        $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', [
            'state_machine' => $this->shortName((string) config('services.workflow.state_machine_arn')),
        ]);
    }

    private function executionName(OriginalDocument $document): string
    {
        return 'poc-doc-'.$document->id.'-'.Str::uuid();
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

    private function documentBucket(OriginalDocument $document): string
    {
        if ($document->s3_bucket) {
            return $document->s3_bucket;
        }

        $disk = (string) config('poc.documents.storage_disk', config('filesystems.default'));

        return (string) config("filesystems.disks.{$disk}.bucket", config('services.textract.s3_bucket'));
    }

    private function documentKey(OriginalDocument $document): string
    {
        if ($document->s3_key) {
            return $document->s3_key;
        }

        $disk = (string) config('poc.documents.storage_disk', config('filesystems.default'));
        $root = trim((string) config("filesystems.disks.{$disk}.root", ''), '/');

        // file_path is always disk-relative (no root prefix), while Textract needs
        // the raw S3 key, which includes the disk root. Always prepend it; the
        // previous str_starts_with shortcut was fooled when the upload folder
        // happened to start with the root (e.g. root "documents" + "documents/originals").
        return $root === '' ? $document->file_path : $root.'/'.$document->file_path;
    }

    private function shortName(string $arn): string
    {
        return Str::of($arn)->afterLast(':')->toString() ?: 'unknown';
    }
}
