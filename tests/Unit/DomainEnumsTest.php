<?php

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageSource;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;

/**
 * Gli enum di dominio alimentano etichette e colori mostrati all'operatore.
 * Verificarli caso per caso conta poco; verificarli in modo esaustivo conta:
 * aggiungere un case senza aggiornare i match manderebbe in errore la UI, e
 * questi test falliscono nel momento in cui succede.
 */
$enums = [
    'CoverImageSource' => CoverImageSource::class,
    'CoverImageStatus' => CoverImageStatus::class,
    'CommunicationGenerationStatus' => CommunicationGenerationStatus::class,
    'SendStatus' => SendStatus::class,
    'ProcessingStatus' => ProcessingStatus::class,
    'ReviewStatus' => ReviewStatus::class,
];

/** Gli enum che espongono anche un colore (CoverImageSource non ne ha). */
$colouredEnums = [
    'CoverImageStatus' => CoverImageStatus::class,
    'CommunicationGenerationStatus' => CommunicationGenerationStatus::class,
    'SendStatus' => SendStatus::class,
    'ProcessingStatus' => ProcessingStatus::class,
    'ReviewStatus' => ReviewStatus::class,
];

test('every case exposes a non empty label', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
})->with($enums);

test('labels are distinct, so two states never read the same', function (string $enum) {
    $labels = array_map(fn ($case) => $case->label(), $enum::cases());

    expect($labels)->toHaveCount(count(array_unique($labels)));
})->with($enums);

test('every case exposes a colour the interface knows', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case->color())->toBeIn(['gray', 'info', 'success', 'warning', 'danger']);
    }
})->with($colouredEnums);

test('a removed cover reads as a neutral outcome, not as a failure', function () {
    // La rimozione e' voluta dall'operatore: colorarla come "danger" la
    // farebbe leggere come un degrado del sistema (ADR 0009).
    expect(CoverImageStatus::Removed->color())->toBe('gray')
        ->and(CoverImageStatus::Failed->color())->toBe('danger');
});

test('a quarantined document is flagged as requiring attention', function () {
    expect(ReviewStatus::Quarantined->color())->toBe('danger')
        ->and(ReviewStatus::ManuallyValidated->color())->toBe('success');
});

test('enum values match the strings persisted in the database', function () {
    expect(array_map(fn ($case) => $case->value, ProcessingStatus::cases()))
        ->toBe(['pending', 'processing', 'completed', 'failed'])
        ->and(array_map(fn ($case) => $case->value, ReviewStatus::cases()))
        ->toBe(['needs_review', 'auto_validated', 'quarantined', 'manually_validated'])
        ->and(array_map(fn ($case) => $case->value, CoverImageStatus::cases()))
        ->toBe(['pending', 'processing', 'ready', 'failed', 'removed'])
        ->and(array_map(fn ($case) => $case->value, CoverImageSource::cases()))
        ->toBe(['ai', 'manual'])
        ->and(array_map(fn ($case) => $case->value, CommunicationGenerationStatus::cases()))
        ->toBe(['pending', 'processing', 'completed', 'failed'])
        // "sent" qui vuol dire scaricata: la comunicazione convertita in PDF e
        // scaricata si considera consegnata, l'invio interno resta fuori scope.
        ->and(array_map(fn ($case) => $case->value, SendStatus::cases()))
        ->toBe(['pending', 'sent']);
});
