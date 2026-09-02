<?php

use App\Mvp\Documents\Application\UseCases\ReviewDocumentService;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\SubDocumentExtractedDataCorrected;
use App\Mvp\Documents\Domain\Events\SubDocumentManuallyValidated;
use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Exceptions\MissingExtractedDataException;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS).
 */
function fakeReviewActor(): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-test', ['mvp-operator']);
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

test('updateExtractedData marks the sub-document as needing review when not validated', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['review_status' => 'auto_validated']);
    $events = new RecordingDocumentEventDispatcher;

    $status = (new ReviewDocumentService($documents, $events))
        ->updateExtractedData(10, ['company_name' => 'Corretta Srl'], markAsValidated: false, actor: fakeReviewActor());

    expect($status)->toBe('needs_review')
        ->and($documents->findSubDocument(10)->reviewStatus())->toBe(ReviewStatus::NeedsReview);
});

test('una correzione non declassa la scheda che una persona aveva gia\' confermato', function () {
    // Il dato e' appena ripassato sotto i suoi occhi: chiedere di confermare
    // due volte la stessa scheda, e tenere spento il download nel frattempo,
    // sarebbe un giro a vuoto.
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['review_status' => 'manually_validated']);
    $events = new RecordingDocumentEventDispatcher;

    $status = (new ReviewDocumentService($documents, $events))
        ->updateExtractedData(10, ['company_name' => 'Corretta Srl'], markAsValidated: false, actor: fakeReviewActor());

    expect($status)->toBe('manually_validated')
        ->and($documents->findSubDocument(10)->reviewStatus())->toBe(ReviewStatus::ManuallyValidated);
});

test('updateExtractedData refuses a sub-document outside the actor tenant scope', function () {
    // Difesa in profondita' (vedi il docblock della classe): il controllo
    // HTTP (AuthorizesDocuments) protegge solo chi lo chiama, questo
    // verifica che il caso d'uso resti sicuro anche da solo.
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $events = new RecordingDocumentEventDispatcher;
    $intruder = new Actor('user-2', 'other@example.test', 'Other', 'altro-tenant', ['mvp-operator']);

    expect(fn () => (new ReviewDocumentService($documents, $events))
        ->updateExtractedData(10, ['company_name' => 'Corretta Srl'], markAsValidated: false, actor: $intruder))
        ->toThrow(DocumentNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty();
});

test('markReviewed refuses a sub-document outside the actor tenant scope', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $documents->saveExtractedData(10, ExtractedDataChanges::none()->withCompanyName('Acme Srl'));
    $events = new RecordingDocumentEventDispatcher;
    $intruder = new Actor('user-2', 'other@example.test', 'Other', 'altro-tenant', ['mvp-operator']);

    expect(fn () => (new ReviewDocumentService($documents, $events))->markReviewed(10, $intruder))
        ->toThrow(DocumentNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty();
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
    $documents->saveExtractedData(10, ExtractedDataChanges::none()->withCompanyName('Acme Srl'));
    $events = new RecordingDocumentEventDispatcher;

    (new ReviewDocumentService($documents, $events))->markReviewed(10, fakeReviewActor());

    expect($events->hasDispatched(SubDocumentManuallyValidated::class))->toBeTrue()
        ->and($documents->findSubDocument(10)->reviewStatus())->toBe(ReviewStatus::ManuallyValidated);
});
