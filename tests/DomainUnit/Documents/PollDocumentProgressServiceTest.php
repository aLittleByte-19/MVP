<?php

use App\Mvp\Documents\Application\UseCases\PollDocumentProgressService;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima il polling SSE
 * di DocumentController::stream() interrogava Eloquent direttamente a ogni
 * iterazione del loop: ora legge tramite PollDocumentProgressUseCase.
 */
test('poll reports processing status, error message and extracted sub-document ids in order', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['processing_status' => 'processing', 'error_message' => null]);
    $documents->seedSubDocument(2, 1);
    $documents->seedSubDocument(1, 1);
    $documents->saveExtractedData(1, ExtractedDataChanges::none()->withEmployeeFirstName('Mario'));
    $documents->saveExtractedData(2, ExtractedDataChanges::none()->withEmployeeFirstName('Luigi'));

    $snapshot = (new PollDocumentProgressService($documents))->poll(1);

    expect($snapshot->processingStatus)->toBe('processing')
        ->and($snapshot->errorMessage)->toBeNull()
        ->and($snapshot->extractedSubDocumentIds)->toBe([1, 2]);
});

test('poll excludes sub-documents without extracted data yet', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(1, 1);
    $documents->seedSubDocument(2, 1);
    $documents->saveExtractedData(1, ExtractedDataChanges::none()->withEmployeeFirstName('Mario'));

    $snapshot = (new PollDocumentProgressService($documents))->poll(1);

    expect($snapshot->extractedSubDocumentIds)->toBe([1]);
});

test('poll surfaces the failure message when processing failed', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['processing_status' => 'failed', 'error_message' => 'Analisi non disponibile.']);

    $snapshot = (new PollDocumentProgressService($documents))->poll(1);

    expect($snapshot->processingStatus)->toBe('failed')
        ->and($snapshot->errorMessage)->toBe('Analisi non disponibile.');
});
