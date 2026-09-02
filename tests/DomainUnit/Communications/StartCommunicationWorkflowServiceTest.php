<?php

use App\Mvp\Communications\Application\UseCases\StartCommunicationWorkflowService;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Events\CommunicationRegenerationRequested;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotAuthorizedException;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Workflow\Support\WorkflowContext;
use Tests\DomainUnit\Communications\Fakes\FakeClock;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationCoverStorage;
use Tests\DomainUnit\Communications\Fakes\FakeUniqueIdGenerator;
use Tests\DomainUnit\Communications\Fakes\FakeWorkflowEngine;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\PassthroughTransactionManager;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Funzione locale (non condivisa fra file): con `--parallel` ogni worker
 * Paratest carica solo un sottoinsieme dei file di test.
 */
function fakeRegenerateActor(): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, ADR 0010): ARN e
 * coda sono passati al costruttore invece che letti da config().
 */
function mvpStartCommunicationWorkflowService(
    InMemoryCommunicationRepository $communications,
    FakeWorkflowEngine $workflowEngine,
    RecordingEventDispatcher $events,
    ?FakeCommunicationCoverStorage $coverStorage = null,
    string $stateMachineArn = 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-communication-pipeline',
    string $taskQueueUrl = 'http://localstack:4566/000000000000/mvp-communications',
): StartCommunicationWorkflowService {
    return new StartCommunicationWorkflowService(
        $communications,
        $coverStorage ?? new FakeCommunicationCoverStorage,
        $workflowEngine,
        $events,
        new WorkflowContext,
        new FakeClock(new DateTimeImmutable('2026-01-01T00:00:00Z')),
        new FakeUniqueIdGenerator,
        new PassthroughTransactionManager,
        $stateMachineArn,
        $taskQueueUrl,
    );
}

test('start communication workflow starts a Step Functions execution', function () {
    $communications = new InMemoryCommunicationRepository;
    $communications->seed(1, ['tone' => 'Tecnico', 'style' => 'Avviso operativo']);
    $workflowEngine = new FakeWorkflowEngine;
    $events = new RecordingEventDispatcher;

    mvpStartCommunicationWorkflowService($communications, $workflowEngine, $events)->start(1, null, null);

    $communication = $communications->findCommunication(1);

    expect($communication->generationStatus())->toBe(CommunicationGenerationStatus::Processing)
        ->and($communication->workflowExecutionArn())->toBe('arn:aws:states:eu-north-1:000000000000:execution:fake:test')
        ->and($workflowEngine->lastCall()['input']['task_queue_url'])->toBe('http://localstack:4566/000000000000/mvp-communications')
        ->and($events->hasDispatched(CommunicationWorkflowStarted::class))->toBeTrue()
        // Senza questo assert, un regresso che perdesse il lock (doppio
        // avvio del workflow) non farebbe fallire nessun test.
        ->and($communications->forUpdateReadCount(1))->toBe(1);
});

test('start communication workflow does not start the same processing execution twice', function () {
    $communications = new InMemoryCommunicationRepository;
    $communications->seed(1, [
        'generation_status' => 'processing',
        'workflow_execution_arn' => 'arn:aws:states:eu-north-1:000000000000:execution:mvp-communication-pipeline:running',
    ]);
    $workflowEngine = new FakeWorkflowEngine;

    mvpStartCommunicationWorkflowService($communications, $workflowEngine, new RecordingEventDispatcher)->start(1, null, null);

    expect($workflowEngine->lastCall())->toBeNull()
        ->and($communications->forUpdateReadCount(1))->toBe(1);
});

test('start communication workflow rejects incomplete runtime configuration', function () {
    $communications = new InMemoryCommunicationRepository;
    $communications->seed(1);
    $workflowEngine = new FakeWorkflowEngine;

    $service = mvpStartCommunicationWorkflowService($communications, $workflowEngine, new RecordingEventDispatcher, stateMachineArn: '', taskQueueUrl: '');

    expect(fn () => $service->start(1, null, null))
        ->toThrow(RuntimeException::class, 'Pipeline comunicazioni non configurata');
    expect($workflowEngine->lastCall())->toBeNull();
});

test('start communication workflow records and exposes a Step Functions start failure', function () {
    $communications = new InMemoryCommunicationRepository;
    $communications->seed(1);
    $workflowEngine = new FakeWorkflowEngine;
    $workflowEngine->willFailWith(new RuntimeException('Access denied'));
    $events = new RecordingEventDispatcher;

    expect(fn () => mvpStartCommunicationWorkflowService($communications, $workflowEngine, $events)->start(1, null, null))
        ->toThrow(RuntimeException::class, 'Access denied');

    $communication = $communications->findCommunication(1);
    expect($communication->generationStatus())->toBe(CommunicationGenerationStatus::Failed)
        ->and($events->hasDispatched(CommunicationWorkflowStartFailed::class))->toBeTrue();
});

test('regenerate deletes the previous cover and restarts the workflow', function () {
    $communications = new InMemoryCommunicationRepository;
    $communications->seed(1, ['generation_status' => 'completed', 'cover_image_path' => 'communications/covers/1/old.png']);
    $workflowEngine = new FakeWorkflowEngine;
    $events = new RecordingEventDispatcher;
    $coverStorage = new FakeCommunicationCoverStorage;
    $coverStorage->store('communications/covers/1/old.png', 'vecchia-copertina');

    mvpStartCommunicationWorkflowService($communications, $workflowEngine, $events, $coverStorage)
        ->regenerate(1, fakeRegenerateActor(), null, null);

    expect($coverStorage->deletedPaths())->toBe(['communications/covers/1/old.png'])
        ->and($events->hasDispatched(CommunicationRegenerationRequested::class))->toBeTrue()
        ->and($events->hasDispatched(CommunicationWorkflowStarted::class))->toBeTrue();
});

test('regenerate refuses a communication outside the actor tenant scope', function () {
    // Difesa in profondita': il controllo HTTP protegge solo chi lo chiama,
    // questo verifica che il caso d'uso resti sicuro anche da solo.
    $communications = new InMemoryCommunicationRepository;
    $communications->seed(1, ['generation_status' => 'completed']);
    $workflowEngine = new FakeWorkflowEngine;
    $events = new RecordingEventDispatcher;
    $intruder = new Actor('user-2', 'other@example.test', 'Other', 'altro-tenant', ['mvp-operator']);

    expect(fn () => mvpStartCommunicationWorkflowService($communications, $workflowEngine, $events)->regenerate(1, $intruder, null, null))
        ->toThrow(CommunicationNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty()
        ->and($workflowEngine->lastCall())->toBeNull();
});
