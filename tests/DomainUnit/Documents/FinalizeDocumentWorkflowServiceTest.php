<?php

use App\Mvp\Documents\Application\UseCases\FinalizeDocumentWorkflowService;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowCompleted;
use Tests\DomainUnit\Documents\Fakes\FakeClock;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima bloccato da
 * now() (facade Date): l'orologio iniettato (Psr\Clock\ClockInterface) lo
 * sblocca, ultimo dei blocchi rimasti dato che il servizio dispatcha gia'
 * eventi invece di chiamare AuditLogger/MetricsRecorder direttamente.
 */
test('dispatchCompletionEvent stamps workflow_completed_at with the injected clock when processing completed', function () {
    $repository = new InMemoryDocumentRepository;
    $repository->seedOriginal(1, ['processing_status' => 'completed', 'workflow_completed_at' => null]);
    $events = new RecordingDocumentEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable('2026-02-01 09:30:00'));

    $result = (new FinalizeDocumentWorkflowService($repository, $events, $clock))->dispatchCompletionEvent(1);

    expect($repository->findOriginalDocument(1)->workflowCompleted)->toBeTrue()
        ->and($result)->toBe(['event' => 'DocumentPipelineCompleted'])
        ->and($events->hasDispatched(DocumentWorkflowCompleted::class))->toBeTrue();
});

test('dispatchCompletionEvent does not stamp workflow_completed_at when processing is not yet completed', function () {
    $repository = new InMemoryDocumentRepository;
    $repository->seedOriginal(1, ['processing_status' => 'processing', 'workflow_completed_at' => null]);
    $events = new RecordingDocumentEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable);

    $result = (new FinalizeDocumentWorkflowService($repository, $events, $clock))->dispatchCompletionEvent(1);

    expect($repository->findOriginalDocument(1)->workflowCompleted)->toBeFalse()
        ->and($result)->toBe(['event' => 'DocumentPipelineProgressed'])
        ->and($events->hasDispatched(DocumentWorkflowCompleted::class))->toBeTrue();
});
