<?php

use App\Mvp\Documents\Domain\Entities\SubDocument;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;

/**
 * Test di dominio puro sull'entità stessa (nessun bootstrap Laravel/DB),
 * indipendente da qualsiasi caso d'uso — spike del Progetto A (modello
 * ricco), vedi ADR 0010.
 */
function fakeSubDocumentRecord(): SubDocumentRecord
{
    return new SubDocumentRecord(
        id: 10,
        originalDocumentId: 1,
        filePath: 'documents/sub/10.pdf',
        startPage: 1,
        endPage: 2,
        originalFilename: 'documento.pdf',
    );
}

test('markAutoValidated transitions reviewStatus and clears errorMessage in pendingChanges', function () {
    $subDocument = SubDocument::fromRecord(fakeSubDocumentRecord(), ReviewStatus::NeedsReview);

    $subDocument->markAutoValidated();

    expect($subDocument->reviewStatus())->toBe(ReviewStatus::AutoValidated)
        ->and($subDocument->pendingChanges()->toArray())->toBe([
            'reviewStatus' => ReviewStatus::AutoValidated,
            'errorMessage' => null,
        ]);
});

test('markNeedsReview transitions reviewStatus', function () {
    $subDocument = SubDocument::fromRecord(fakeSubDocumentRecord(), ReviewStatus::AutoValidated);

    $subDocument->markNeedsReview();

    expect($subDocument->reviewStatus())->toBe(ReviewStatus::NeedsReview);
});

test('markManuallyValidated transitions reviewStatus regardless of the starting status', function () {
    $subDocument = SubDocument::fromRecord(fakeSubDocumentRecord(), ReviewStatus::Quarantined);

    $subDocument->markManuallyValidated();

    expect($subDocument->reviewStatus())->toBe(ReviewStatus::ManuallyValidated);
});

test('markQuarantined transitions reviewStatus and records the reason as errorMessage', function () {
    $subDocument = SubDocument::fromRecord(fakeSubDocumentRecord(), ReviewStatus::NeedsReview);

    $subDocument->markQuarantined('Output AI non conforme: sotto-documento in quarantena.');

    expect($subDocument->reviewStatus())->toBe(ReviewStatus::Quarantined)
        ->and($subDocument->pendingChanges()->toArray())->toBe([
            'reviewStatus' => ReviewStatus::Quarantined,
            'errorMessage' => 'Output AI non conforme: sotto-documento in quarantena.',
        ]);
});

test('pendingChanges accumulates only the fields actually changed, not the untouched structural fields', function () {
    $subDocument = SubDocument::fromRecord(fakeSubDocumentRecord(), ReviewStatus::NeedsReview);

    expect($subDocument->pendingChanges()->isEmpty())->toBeTrue();

    $subDocument->markAutoValidated();

    expect($subDocument->pendingChanges()->isEmpty())->toBeFalse()
        ->and($subDocument->id)->toBe(10)
        ->and($subDocument->filePath)->toBe('documents/sub/10.pdf');
});

test('markSent transitions sendStatus once and is a no-op the second time', function () {
    $subDocument = SubDocument::fromRecord(fakeSubDocumentRecord(), ReviewStatus::NeedsReview);

    $subDocument->markSent();

    expect($subDocument->sendStatus())->toBe(SendStatus::Sent)
        ->and($subDocument->pendingChanges()->toArray())->toHaveKey('sendStatus');

    $subDocument->markSent();

    expect($subDocument->sendStatus())->toBe(SendStatus::Sent);
});
