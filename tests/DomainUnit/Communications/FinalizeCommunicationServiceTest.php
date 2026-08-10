<?php

use App\Mvp\Communications\Application\UseCases\FinalizeCommunicationService;
use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowCompleted;
use Tests\DomainUnit\Communications\Fakes\FakeClock;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima bloccato da
 * now() (facade Date): l'orologio iniettato (Psr\Clock\ClockInterface) lo
 * sblocca, ultimo dei blocchi rimasti dato che il servizio dispatcha gia'
 * eventi invece di chiamare AuditLogger/MetricsRecorder direttamente.
 */
test('finalize completes the workflow with the injected clock and dispatches CommunicationWorkflowCompleted', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_status' => 'completed']);
    $events = new RecordingEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable('2026-02-01 09:30:00'));

    $result = (new FinalizeCommunicationService($repository, $events, $clock))->finalize(1);

    expect($repository->findCommunication(1)->generationStatus)->toBe('completed')
        ->and($result)->toBe(['event' => 'CommunicationPipelineCompleted', 'coverStatus' => 'completed'])
        ->and($events->hasDispatched(CommunicationWorkflowCompleted::class))->toBeTrue()
        ->and($events->hasDispatched(CommunicationCoverDegraded::class))->toBeFalse();
});

test('finalize degrades a cover stuck pending and dispatches CommunicationCoverDegraded', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_status' => 'pending']);
    $events = new RecordingEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable);

    $result = (new FinalizeCommunicationService($repository, $events, $clock))->finalize(1);

    expect($repository->findCommunication(1)->coverStatus)->toBe('failed')
        ->and($result)->toBe(['event' => 'CommunicationPipelineCompleted', 'coverStatus' => 'failed'])
        ->and($events->hasDispatched(CommunicationCoverDegraded::class))->toBeTrue();
});
