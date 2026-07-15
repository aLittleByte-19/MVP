<?php

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Workflow\Services\DocumentWorkflowService;
use App\Copilot\Workflow\Services\DocumentWorkflowTaskHandler;
use App\Models\Copilot\AuditEvent;
use App\Models\Copilot\DocumentWorkflowTask;
use App\Models\Copilot\OriginalDocument;
use Aws\Result;
use Aws\Sfn\SfnClient;

test('document workflow service starts a Step Functions execution and stores metadata', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'filesystems.default' => 's3',
        'filesystems.disks.s3.bucket' => 'mvp-documents-local',
        'filesystems.disks.s3.root' => null,
    ]);

    $client = Mockery::mock(SfnClient::class);
    $client->shouldReceive('startExecution')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            $input = json_decode($payload['input'], true);

            return $payload['stateMachineArn'] === 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline'
                && str_starts_with($payload['name'], 'mvp-doc-')
                && $input['document_id'] > 0
                && $input['task_queue_url'] === 'http://localstack:4566/000000000000/mvp-documents'
                && $input['s3_bucket'] === 'mvp-documents-local';
        }))
        ->andReturn(new Result([
            'executionArn' => 'arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:test',
        ]));

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Pending,
        'file_path' => 'documents/originals/test.pdf',
    ]);
    $service = new DocumentWorkflowService($client, app(AuditLogger::class), app(MetricsRecorder::class));

    $started = $service->start($document);

    expect($started->processing_status)->toBe(ProcessingStatus::Processing)
        ->and($started->workflow_execution_arn)->toBe('arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:test')
        ->and($started->s3_bucket)->toBe('mvp-documents-local')
        ->and($started->s3_key)->toBe('documents/originals/test.pdf')
        ->and(AuditEvent::query()->where('event_type', 'mvp-document-workflow-started')->count())->toBe(1);
});

test('workflow task handler processes textract task idempotently when textract is disabled', function () {
    config(['services.textract.enabled' => false]);

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
        's3_bucket' => 'real-bucket',
        's3_key' => 'documents/test.pdf',
    ]);
    $handler = app(DocumentWorkflowTaskHandler::class);
    $message = [
        'taskToken' => 'opaque-token',
        'taskType' => 'textract.ocr',
        'documentId' => $document->id,
        'tenantId' => $document->tenant_id,
        'correlationId' => 'corr-1',
        'requestId' => 'req-1',
        's3Bucket' => 'real-bucket',
        's3Key' => 'documents/test.pdf',
        'taskQueueUrl' => 'http://localstack/queue',
    ];

    $first = $handler->handle($message);
    $second = $handler->handle($message);

    expect($first['callback_required'])->toBeTrue()
        ->and($first['output']['task_result']['status'])->toBe('skipped')
        ->and($second['callback_required'])->toBeFalse()
        ->and(DocumentWorkflowTask::query()->count())->toBe(1)
        ->and(DocumentWorkflowTask::query()->first()->status)->toBe('skipped');
});

test('workflow task handler does not re-run a task already running on another worker', function () {
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
    ]);

    DocumentWorkflowTask::query()->create([
        'original_document_id' => $document->id,
        'task_type' => 'textract.ocr',
        'task_token_hash' => hash('sha256', 'in-flight-token'),
        'status' => 'running',
        'started_at' => now()->subSeconds(30),
    ]);

    $handler = app(DocumentWorkflowTaskHandler::class);
    $result = $handler->handle([
        'taskToken' => 'in-flight-token',
        'taskType' => 'textract.ocr',
        'documentId' => $document->id,
    ]);

    expect($result['callback_required'])->toBeFalse()
        ->and($result['output']['task_result']['duplicate_in_flight'])->toBeTrue()
        ->and(DocumentWorkflowTask::query()->sole()->status)->toBe('running');
});

test('workflow task handler re-claims a stale running task left by a dead worker', function () {
    config(['services.textract.enabled' => false, 'mvp.workflow.running_claim_ttl_seconds' => 900]);

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
    ]);

    DocumentWorkflowTask::query()->create([
        'original_document_id' => $document->id,
        'task_type' => 'textract.ocr',
        'task_token_hash' => hash('sha256', 'stale-token'),
        'status' => 'running',
        'started_at' => now()->subSeconds(2000),
    ]);

    $handler = app(DocumentWorkflowTaskHandler::class);
    $result = $handler->handle([
        'taskToken' => 'stale-token',
        'taskType' => 'textract.ocr',
        'documentId' => $document->id,
    ]);

    // Textract disabilitato: il task ri-conquistato termina come skipped.
    expect($result['callback_required'])->toBeTrue()
        ->and(DocumentWorkflowTask::query()->sole()->status)->toBe('skipped');
});
