<?php

use App\Mvp\Documents\Application\UseCases\ReviewDocumentService;
use App\Mvp\Documents\Domain\Events\SubDocumentExtractedDataCorrected;
use App\Mvp\Documents\Domain\Events\SubDocumentManuallyValidated;
use App\Mvp\Documents\Domain\Exceptions\MissingExtractedDataException;
use App\Mvp\Identity\MvpUser;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS).
 */
function fakeReviewActor(): MvpUser
{
    return new MvpUser('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

test('updateExtractedData saves the corrected fields and dispatches SubDocumentExtractedDataCorrected', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $events = new RecordingDocumentEventDispatcher;

    $status = (new ReviewDocumentService($documents, $events))
        ->updateExtractedData(10, ['company_name' => 'Corretta Srl'], markAsValidated: true, actor: fakeReviewActor());

    expect($status)->toBe('manually_validated')
        ->and($documents->extractedDataFor(10)['company_name'])->toBe('Corretta Srl')
        ->and($events->hasDispatched(SubDocumentExtractedDataCorrected::class))->toBeTrue();
});

test('markReviewed refuses a sub-document without extracted data', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $events = new RecordingDocumentEventDispatcher;

    expect(fn () => (new ReviewDocumentService($documents, $events))->markReviewed(10, fakeReviewActor()))
        ->toThrow(MissingExtractedDataException::class)
        ->and($events->events())->toBeEmpty();
});

test('markReviewed validates a sub-document that already has extracted data', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $documents->saveExtractedData(10, ['company_name' => 'Acme Srl']);
    $events = new RecordingDocumentEventDispatcher;

    (new ReviewDocumentService($documents, $events))->markReviewed(10, fakeReviewActor());

    expect($events->hasDispatched(SubDocumentManuallyValidated::class))->toBeTrue();
});
