<?php

use App\Mvp\Documents\Application\UseCases\StartDocumentWorkflowService;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStarted;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStartFailed;
use App\Mvp\Workflow\Support\WorkflowContext;
use Tests\DomainUnit\Documents\Fakes\FakeClock;
use Tests\DomainUnit\Documents\Fakes\FakeUniqueIdGenerator;
use Tests\DomainUnit\Documents\Fakes\FakeWorkflowEngine;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\PassthroughTransactionManager;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi ADR 0010): la
 * configurazione (ARN, coda, flag Textract, disco/bucket) e' passata al
 * costruttore invece che letta da config() dentro la classe, e WorkflowContext
 * non tocca piu' la facade Log (vedi il suo docblock) — le due cose insieme
 * rendono start() istanziabile ed eseguibile qui, non solo il costruttore.
 */
function mvpDomainStartDocumentWorkflowService(
    InMemoryDocumentRepository $documents,
    FakeWorkflowEngine $workflowEngine,
    RecordingDocumentEventDispatcher $events,
    string $stateMachineArn = 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
    string $taskQueueUrl = 'http://localstack:4566/000000000000/mvp-documents',
    bool $textractEnabled = false,
    string $storageDisk = 's3',
    string $documentBucketFallback = 'mvp-documents-local',
    string $documentKeyPrefix = '',
): StartDocumentWorkflowService {
    return new StartDocumentWorkflowService(
        $documents,
        $workflowEngine,
        $events,
        new WorkflowContext,
        new FakeClock(new DateTimeImmutable('2026-01-01T00:00:00Z')),
        new FakeUniqueIdGenerator,
        new PassthroughTransactionManager,
        $stateMachineArn,
        $taskQueueUrl,
        $textractEnabled,
        $storageDisk,
        $documentBucketFallback,
        $documentKeyPrefix,
    );
}

test('start document workflow starts a Step Functions execution and stores metadata', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['processing_status' => 'pending', 'file_path' => 'documents/originals/test.pdf']);
    $workflowEngine = new FakeWorkflowEngine;
    $events = new RecordingDocumentEventDispatcher;

    mvpDomainStartDocumentWorkflowService($documents, $workflowEngine, $events)->start(1, null, null);

    $document = $documents->findOriginalDocument(1);

    expect($document->processingStatus())->toBe(ProcessingStatus::Processing)
        ->and($document->workflowExecutionArn())->toBe('arn:aws:states:eu-north-1:000000000000:execution:fake:test')
        ->and($document->s3Bucket())->toBe('mvp-documents-local')
        ->and($document->s3Key())->toBe('documents/originals/test.pdf')
        ->and($workflowEngine->lastCall()['input']['s3_bucket'])->toBe('mvp-documents-local')
        ->and($events->hasDispatched(DocumentWorkflowStarted::class))->toBeTrue();
});

test('start document workflow does not start the same processing execution twice', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, [
        'processing_status' => 'processing',
        'workflow_execution_arn' => 'arn:aws:states:eu-north-1:000000000000:execution:mvp-document-pipeline:running',
    ]);
    $workflowEngine = new FakeWorkflowEngine;

    mvpDomainStartDocumentWorkflowService($documents, $workflowEngine, new RecordingDocumentEventDispatcher)->start(1, null, null);

    expect($workflowEngine->lastCall())->toBeNull();
});

test('start document workflow rejects incomplete runtime configuration', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $workflowEngine = new FakeWorkflowEngine;

    $service = mvpDomainStartDocumentWorkflowService($documents, $workflowEngine, new RecordingDocumentEventDispatcher, stateMachineArn: '', taskQueueUrl: '');

    expect(fn () => $service->start(1, null, null))
        ->toThrow(RuntimeException::class, 'Workflow documentale non configurato');
    expect($workflowEngine->lastCall())->toBeNull();
});

test('start document workflow rejects real Textract with a local document disk', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $workflowEngine = new FakeWorkflowEngine;

    $service = mvpDomainStartDocumentWorkflowService($documents, $workflowEngine, new RecordingDocumentEventDispatcher, textractEnabled: true, storageDisk: 's3');

    expect(fn () => $service->start(1, null, null))
        ->toThrow(RuntimeException::class, 'Textract è abilitato');
    expect($workflowEngine->lastCall())->toBeNull();
});

test('start document workflow records and exposes a Step Functions start failure', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['processing_status' => 'pending', 'file_path' => 'documents/originals/test.pdf']);
    $workflowEngine = new FakeWorkflowEngine;
    $workflowEngine->willFailWith(new RuntimeException('Impossibile avviare la pipeline Step Functions: Access denied'));
    $events = new RecordingDocumentEventDispatcher;

    expect(fn () => mvpDomainStartDocumentWorkflowService($documents, $workflowEngine, $events)->start(1, null, null))
        ->toThrow(RuntimeException::class, 'Impossibile avviare la pipeline Step Functions');

    $document = $documents->findOriginalDocument(1);
    expect($document->processingStatus())->toBe(ProcessingStatus::Failed)
        ->and($document->errorMessage())->toBe('Avvio workflow documentale non disponibile.')
        ->and($events->hasDispatched(DocumentWorkflowStartFailed::class))->toBeTrue();
});
