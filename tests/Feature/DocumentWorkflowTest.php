<?php

use App\Models\AuditEvent;
use App\Models\OriginalDocument;
use App\Models\WorkflowTask;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Documents\Services\DocumentWorkflowService;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskRunner;
use Aws\Command;
use Aws\Exception\AwsException;
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

test('document workflow does not start the same processing execution twice', function () {
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
        'workflow_execution_arn' => 'arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:running',
    ]);
    $client = Mockery::mock(SfnClient::class);
    $client->shouldNotReceive('startExecution');
    $service = new DocumentWorkflowService($client, app(AuditLogger::class), app(MetricsRecorder::class));

    expect($service->start($document))->toBe($document);
});

test('document workflow rejects incomplete runtime configuration', function () {
    config([
        'services.workflow.state_machine_arn' => '',
        'services.workflow.task_queue_url' => '',
        'queue.connections.sqs.prefix' => '',
        'queue.connections.sqs.queue' => '',
    ]);
    $client = Mockery::mock(SfnClient::class);
    $client->shouldNotReceive('startExecution');
    $service = new DocumentWorkflowService($client, app(AuditLogger::class), app(MetricsRecorder::class));

    expect(fn () => $service->start(OriginalDocument::factory()->create()))
        ->toThrow(RuntimeException::class, 'Workflow documentale non configurato');
});

test('document workflow rejects real Textract with a local document disk', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'services.textract.enabled' => true,
        'mvp.documents.storage_disk' => 's3',
    ]);
    $client = Mockery::mock(SfnClient::class);
    $client->shouldNotReceive('startExecution');
    $service = new DocumentWorkflowService($client, app(AuditLogger::class), app(MetricsRecorder::class));

    expect(fn () => $service->start(OriginalDocument::factory()->create()))
        ->toThrow(RuntimeException::class, 'Textract è abilitato');
});

test('document workflow records and exposes a Step Functions start failure', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'services.textract.enabled' => false,
        'mvp.documents.storage_disk' => 's3',
        'filesystems.disks.s3.bucket' => 'mvp-documents-local',
    ]);
    $failure = new AwsException('Access denied', new Command('StartExecution'), [
        'code' => 'AccessDeniedException',
        'message' => 'Step Functions denied the request',
    ]);
    $client = Mockery::mock(SfnClient::class);
    $client->shouldReceive('startExecution')->once()->andThrow($failure);
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Pending,
        'file_path' => 'documents/originals/test.pdf',
    ]);
    $service = new DocumentWorkflowService($client, app(AuditLogger::class), app(MetricsRecorder::class));

    expect(fn () => $service->start($document))
        ->toThrow(RuntimeException::class, 'Impossibile avviare la pipeline Step Functions');

    $document->refresh();
    expect($document->processing_status)->toBe(ProcessingStatus::Failed)
        ->and($document->workflow_failure_reason)->toBe('Access denied')
        ->and($document->error_message)->toBe('Avvio workflow documentale non disponibile.')
        ->and(AuditEvent::query()->where('event_type', 'mvp-document-workflow-start-failed')->count())->toBe(1);
});

test('document workflow persists unexpected start failures before rethrowing them', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'services.textract.enabled' => false,
        'mvp.documents.storage_disk' => 's3',
        'filesystems.disks.s3.bucket' => 'mvp-documents-local',
    ]);
    $client = Mockery::mock(SfnClient::class);
    $client->shouldReceive('startExecution')->once()->andThrow(new LogicException('Invalid local payload'));
    $document = OriginalDocument::factory()->create(['file_path' => 'documents/originals/test.pdf']);
    $service = new DocumentWorkflowService($client, app(AuditLogger::class), app(MetricsRecorder::class));

    expect(fn () => $service->start($document))->toThrow(LogicException::class, 'Invalid local payload');
    expect($document->refresh()->processing_status)->toBe(ProcessingStatus::Failed);
});

test('workflow runner processes textract task idempotently when textract is disabled', function () {
    config(['services.textract.enabled' => false]);

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
        's3_bucket' => 'real-bucket',
        's3_key' => 'documents/test.pdf',
    ]);
    $runner = app(WorkflowTaskRunner::class);
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

    $first = $runner->handle($message);
    $second = $runner->handle($message);

    expect($first['callback_required'])->toBeTrue()
        ->and($first['output']['task_result']['status'])->toBe('skipped')
        ->and($second['callback_required'])->toBeFalse()
        ->and(WorkflowTask::query()->count())->toBe(1)
        ->and(WorkflowTask::query()->first()->status)->toBe('skipped')
        ->and(WorkflowTask::query()->first()->subject_type)->toBe('original_document');
});

test('workflow runner does not re-run a task already running on another worker', function () {
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
    ]);

    WorkflowTask::query()->create([
        'subject_type' => 'original_document',
        'subject_id' => $document->id,
        'task_type' => 'textract.ocr',
        'task_token_hash' => hash('sha256', 'in-flight-token'),
        'status' => 'running',
        'started_at' => now()->subSeconds(30),
    ]);

    $runner = app(WorkflowTaskRunner::class);
    $result = $runner->handle([
        'taskToken' => 'in-flight-token',
        'taskType' => 'textract.ocr',
        'documentId' => $document->id,
    ]);

    expect($result['callback_required'])->toBeFalse()
        ->and($result['output']['task_result']['duplicate_in_flight'])->toBeTrue()
        ->and(WorkflowTask::query()->sole()->status)->toBe('running');
});

test('workflow runner re-claims a stale running task left by a dead worker', function () {
    config(['services.textract.enabled' => false, 'mvp.workflow.running_claim_ttl_seconds' => 900]);

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
    ]);

    WorkflowTask::query()->create([
        'subject_type' => 'original_document',
        'subject_id' => $document->id,
        'task_type' => 'textract.ocr',
        'task_token_hash' => hash('sha256', 'stale-token'),
        'status' => 'running',
        'started_at' => now()->subSeconds(2000),
    ]);

    $runner = app(WorkflowTaskRunner::class);
    $result = $runner->handle([
        'taskToken' => 'stale-token',
        'taskType' => 'textract.ocr',
        'documentId' => $document->id,
    ]);

    // Textract disabilitato: il task ri-conquistato termina come skipped.
    expect($result['callback_required'])->toBeTrue()
        ->and(WorkflowTask::query()->sole()->status)->toBe('skipped');
});

test('workflow runner rejects a task type without a registered handler', function () {
    expect(fn () => app(WorkflowTaskRunner::class)->handle([
        'taskToken' => 'token',
        'taskType' => 'unknown.task',
        'documentId' => 1,
    ]))->toThrow(InvalidArgumentException::class, 'Task workflow non supportato: unknown.task');
});
