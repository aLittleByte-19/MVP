<?php

use App\Models\AuditEvent;
use App\Models\OriginalDocument;
use App\Models\WorkflowTask;
use App\Mvp\Documents\Application\UseCases\StartDocumentWorkflowService;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Services\WorkflowTaskRunner;
use Mockery\MockInterface;

/**
 * Testa StartDocumentWorkflowService (adapter secondario WorkflowEnginePort
 * mockato, resto reale) invece del vecchio DocumentWorkflowService: stessa
 * copertura, ma al confine della porta invece che dell'SDK AWS diretto.
 */
function mvpStartDocumentWorkflowService(?MockInterface $workflowEngine = null): StartDocumentWorkflowService
{
    if ($workflowEngine !== null) {
        app()->instance(WorkflowEnginePort::class, $workflowEngine);
    }

    return app(StartDocumentWorkflowService::class);
}

test('start document workflow service starts a Step Functions execution and stores metadata', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'filesystems.default' => 's3',
        'filesystems.disks.s3.bucket' => 'mvp-documents-local',
        'filesystems.disks.s3.root' => null,
    ]);

    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldReceive('startExecution')
        ->once()
        ->with(
            'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
            Mockery::on(fn (string $name) => str_starts_with($name, 'mvp-doc-')),
            Mockery::on(function (array $input): bool {
                return $input['document_id'] > 0
                    && $input['task_queue_url'] === 'http://localstack:4566/000000000000/mvp-documents'
                    && $input['s3_bucket'] === 'mvp-documents-local';
            }),
        )
        ->andReturn('arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:test');

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Pending,
        'file_path' => 'documents/originals/test.pdf',
    ]);

    mvpStartDocumentWorkflowService($engine)->start($document->id, null, null);

    $document->refresh();
    expect($document->processing_status)->toBe(ProcessingStatus::Processing)
        ->and($document->workflow_execution_arn)->toBe('arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:test')
        ->and($document->s3_bucket)->toBe('mvp-documents-local')
        ->and($document->s3_key)->toBe('documents/originals/test.pdf')
        ->and(AuditEvent::query()->where('event_type', 'mvp-document-workflow-started')->count())->toBe(1);
});

test('start document workflow does not start the same processing execution twice', function () {
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
        'workflow_execution_arn' => 'arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:running',
    ]);
    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldNotReceive('startExecution');

    mvpStartDocumentWorkflowService($engine)->start($document->id, null, null);

    expect(true)->toBeTrue(); // Nessuna eccezione: il mock avrebbe fallito se startExecution fosse stato chiamato.
});

test('start document workflow rejects incomplete runtime configuration', function () {
    config([
        'services.workflow.state_machine_arn' => '',
        'services.workflow.task_queue_url' => '',
        'queue.connections.sqs.prefix' => '',
        'queue.connections.sqs.queue' => '',
    ]);
    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldNotReceive('startExecution');
    $document = OriginalDocument::factory()->create();

    expect(fn () => mvpStartDocumentWorkflowService($engine)->start($document->id, null, null))
        ->toThrow(RuntimeException::class, 'Workflow documentale non configurato');
});

test('start document workflow rejects real Textract with a local document disk', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'services.textract.enabled' => true,
        'mvp.documents.storage_disk' => 's3',
    ]);
    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldNotReceive('startExecution');
    $document = OriginalDocument::factory()->create();

    expect(fn () => mvpStartDocumentWorkflowService($engine)->start($document->id, null, null))
        ->toThrow(RuntimeException::class, 'Textract è abilitato');
});

test('start document workflow records and exposes a Step Functions start failure', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'services.textract.enabled' => false,
        'mvp.documents.storage_disk' => 's3',
        'filesystems.disks.s3.bucket' => 'mvp-documents-local',
    ]);
    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldReceive('startExecution')
        ->once()
        ->andThrow(new RuntimeException('Impossibile avviare la pipeline Step Functions: Access denied'));
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Pending,
        'file_path' => 'documents/originals/test.pdf',
    ]);

    expect(fn () => mvpStartDocumentWorkflowService($engine)->start($document->id, null, null))
        ->toThrow(RuntimeException::class, 'Impossibile avviare la pipeline Step Functions');

    $document->refresh();
    expect($document->processing_status)->toBe(ProcessingStatus::Failed)
        ->and($document->workflow_failure_reason)->toBe('Impossibile avviare la pipeline Step Functions: Access denied')
        ->and($document->error_message)->toBe('Avvio workflow documentale non disponibile.')
        ->and(AuditEvent::query()->where('event_type', 'mvp-document-workflow-start-failed')->count())->toBe(1);
});

test('start document workflow persists unexpected start failures before rethrowing them', function () {
    config([
        'services.workflow.state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents',
        'services.textract.enabled' => false,
        'mvp.documents.storage_disk' => 's3',
        'filesystems.disks.s3.bucket' => 'mvp-documents-local',
    ]);
    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldReceive('startExecution')->once()->andThrow(new LogicException('Invalid local payload'));
    $document = OriginalDocument::factory()->create(['file_path' => 'documents/originals/test.pdf']);

    expect(fn () => mvpStartDocumentWorkflowService($engine)->start($document->id, null, null))
        ->toThrow(LogicException::class, 'Invalid local payload');
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

test('workflow runner skips bedrock.extract on a document already completed without calling the AI gateway', function () {
    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Completed,
    ]);

    $ai = Mockery::mock(DocumentAiGatewayPort::class);
    $ai->shouldNotReceive('splitDocument');
    $ai->shouldNotReceive('extractFields');
    app()->instance(DocumentAiGatewayPort::class, $ai);

    $runner = app(WorkflowTaskRunner::class);
    $result = $runner->handle([
        'taskToken' => 'token-bedrock-skip',
        'taskType' => 'bedrock.extract',
        'documentId' => $document->id,
    ]);

    expect($result['output']['task_result']['status'])->toBe('skipped')
        ->and(WorkflowTask::query()->sole()->status)->toBe('skipped');
});
