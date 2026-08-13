<?php

use App\Mvp\Communications\Domain\Entities\Communication;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationRegenerationUnavailableException;
use App\Mvp\Communications\Domain\Exceptions\CoverPrecedesTextException;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

/**
 * Test di dominio puro sull'entità stessa (nessun bootstrap Laravel/DB),
 * indipendente da qualsiasi caso d'uso — Progetto A (modello ricco) Fase 2,
 * vedi ADR 0010. Assorbe anche i test di CommunicationDraftBuilder
 * (ritirato: la sua unica invariante, "la copertina non precede il testo",
 * ora vive su applyGeneratedCover()).
 */
function fakeCommunicationRecord(array $overrides = []): CommunicationRecord
{
    return new CommunicationRecord(
        id: $overrides['id'] ?? 1,
        tenantId: $overrides['tenantId'] ?? 'tenant-1',
        prompt: $overrides['prompt'] ?? 'prompt',
        tone: $overrides['tone'] ?? 'tone',
        style: $overrides['style'] ?? 'style',
        generatedTitle: $overrides['generatedTitle'] ?? null,
        generatedBody: $overrides['generatedBody'] ?? null,
        imagePrompt: $overrides['imagePrompt'] ?? null,
        generationStatus: $overrides['generationStatus'] ?? 'pending',
        coverImagePath: $overrides['coverImagePath'] ?? null,
        coverImageMime: $overrides['coverImageMime'] ?? null,
        coverStatus: $overrides['coverStatus'] ?? 'pending',
        status: $overrides['status'] ?? 'draft',
        isFavorite: $overrides['isFavorite'] ?? false,
        rating: $overrides['rating'] ?? null,
        workflowExecutionArn: $overrides['workflowExecutionArn'] ?? null,
        coverError: $overrides['coverError'] ?? null,
        errorMessage: $overrides['errorMessage'] ?? null,
    );
}

test('favorite/unfavorite transition isFavorite and refuse a redundant call', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['isFavorite' => false]));

    $communication->favorite();

    expect($communication->isFavorite())->toBeTrue()
        ->and(fn () => $communication->favorite())->toThrow(CommunicationAlreadyFavoritedException::class);

    $communication->unfavorite();

    expect($communication->isFavorite())->toBeFalse()
        ->and(fn () => $communication->unfavorite())->toThrow(CommunicationNotFavoritedException::class);
});

test('updateDraft refuses a discarded communication', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['status' => 'discarded']));

    expect(fn () => $communication->updateDraft('Titolo', 'Corpo'))->toThrow(CommunicationNotEditableException::class);
});

test('approve transitions status and refuses a communication that is not a draft', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['status' => 'approved']));

    expect(fn () => $communication->approve())->toThrow(CommunicationNotDraftException::class);
});

test('discard transitions status and refuses an already discarded communication', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['status' => 'draft']));

    $communication->discard();

    expect($communication->status())->toBe(CommunicationStatus::Discarded)
        ->and(fn () => $communication->discard())->toThrow(CommunicationAlreadyDiscardedException::class);
});

test('rate refuses a communication already rated', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['rating' => 3]));

    expect(fn () => $communication->rate(5, null, 'user-1', new DateTimeImmutable))->toThrow(CommunicationAlreadyRatedException::class);
});

test('replaceCover/removeCover refuse a discarded communication', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['status' => 'discarded']));

    expect(fn () => $communication->replaceCover('path.png', 'image/png', 10))->toThrow(CommunicationNotEditableException::class)
        ->and(fn () => $communication->removeCover())->toThrow(CommunicationNotEditableException::class)
        ->and($communication->isEditable())->toBeFalse();
});

test('applyGeneratedCover refuses to run before the text step', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['generatedBody' => null]));
    $image = new GeneratedCommunicationImage(bytes: 'bytes', mime: 'image/png', warning: null, reason: null);

    expect($communication->hasGeneratedText())->toBeFalse()
        ->and(fn () => $communication->applyGeneratedCover($image, 'communications/covers/1/x.png'))->toThrow(CoverPrecedesTextException::class);
});

test('applyGeneratedText then applyGeneratedCover accumulate the persistence content once the text exists', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord());
    $generated = new GeneratedCommunicationText(title: 'Titolo', body: 'Corpo', imagePrompt: 'Direzione visiva');

    $communication->applyGeneratedText($generated);

    expect($communication->hasGeneratedText())->toBeTrue()
        ->and($communication->generatedTitle())->toBe('Titolo')
        ->and($communication->generatedBody())->toBe('Corpo');

    $image = new GeneratedCommunicationImage(bytes: 'immagine-bytes', mime: 'image/png', warning: null, reason: null);
    $communication->applyGeneratedCover($image, 'communications/covers/1/x.png');

    expect($communication->coverImagePath())->toBe('communications/covers/1/x.png')
        ->and($communication->pendingChanges()->toArray())
        ->toMatchArray([
            'coverImagePath' => 'communications/covers/1/x.png',
            'coverImageMime' => 'image/png',
            'coverImageSize' => strlen('immagine-bytes'),
        ]);
});

test('degradeCover sets coverStatus to Failed and records the warning', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['coverStatus' => 'processing']));

    $communication->degradeCover('Copertina AI non disponibile.');

    expect($communication->coverStatus())->toBe(CoverImageStatus::Failed)
        ->and($communication->coverError())->toBe('Copertina AI non disponibile.');
});

test('startGeneration then failGeneration then completeGeneration transition generationStatus', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord());
    $now = new DateTimeImmutable('2026-01-15 10:00:00');

    $communication->startGeneration('arn:execution-1', $now);

    expect($communication->generationStatus())->toBe(CommunicationGenerationStatus::Processing)
        ->and($communication->workflowExecutionArn())->toBe('arn:execution-1');

    $communication->failGeneration('Avvio non disponibile.', 'timeout tecnico', $now);

    expect($communication->generationStatus())->toBe(CommunicationGenerationStatus::Failed)
        ->and($communication->errorMessage())->toBe('Avvio non disponibile.');

    $communication->completeGeneration($now);

    expect($communication->generationStatus())->toBe(CommunicationGenerationStatus::Completed);
});

test('regenerate refuses a discarded communication', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['status' => 'discarded', 'generationStatus' => 'completed']));

    expect(fn () => $communication->regenerate())->toThrow(CommunicationAlreadyDiscardedException::class);
});

test('regenerate refuses a communication still processing', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord(['generationStatus' => 'processing']));

    expect(fn () => $communication->regenerate())->toThrow(CommunicationRegenerationUnavailableException::class);
});

test('regenerate resets the generated content once available for a concluded generation', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord([
        'generationStatus' => 'completed',
        'generatedTitle' => 'Vecchio titolo',
        'generatedBody' => 'Vecchio corpo',
        'coverImagePath' => 'communications/covers/1/old.png',
    ]));

    $communication->regenerate();

    expect($communication->generatedTitle())->toBeNull()
        ->and($communication->generatedBody())->toBeNull()
        ->and($communication->coverImagePath())->toBeNull()
        ->and($communication->generationStatus())->toBe(CommunicationGenerationStatus::Pending);
});

test('isReadyForExport reflects generationStatus and status together', function () {
    expect(Communication::fromRecord(fakeCommunicationRecord(['generationStatus' => 'processing']))->isReadyForExport())->toBeFalse()
        ->and(Communication::fromRecord(fakeCommunicationRecord(['generationStatus' => 'completed', 'status' => 'discarded']))->isReadyForExport())->toBeFalse()
        ->and(Communication::fromRecord(fakeCommunicationRecord(['generationStatus' => 'completed', 'status' => 'approved']))->isReadyForExport())->toBeTrue();
});

test('pendingChanges accumulates only the fields actually changed, not the untouched structural fields', function () {
    $communication = Communication::fromRecord(fakeCommunicationRecord());

    expect($communication->pendingChanges()->isEmpty())->toBeTrue();

    $communication->favorite();

    expect($communication->pendingChanges()->isEmpty())->toBeFalse()
        ->and($communication->id)->toBe(1)
        ->and($communication->prompt)->toBe('prompt');
});
