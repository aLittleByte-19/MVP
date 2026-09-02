<?php

use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB): ExtractedDataChanges
 * non ha dipendenze framework.
 */
test('fromRawFields accepts the reviewable fields and converts snake_case to camelCase', function () {
    $changes = ExtractedDataChanges::fromRawFields([
        'employee_first_name' => 'Mario',
        'recipient_email' => 'mario.rossi@example.test',
    ]);

    expect($changes->toArray())->toBe([
        'employeeFirstName' => 'Mario',
        'recipientEmail' => 'mario.rossi@example.test',
    ]);
});

test('fromRawFields refuses a key outside the reviewable fields', function () {
    // Difesa in profondita' (vedi il docblock della whitelist): oggi
    // l'unico chiamante (DocumentReviewController) filtra gia' a monte,
    // questo verifica che il value object resti sicuro anche da solo contro
    // un futuro chiamante meno disciplinato.
    expect(fn () => ExtractedDataChanges::fromRawFields(['field_confidences' => ['employee_first_name' => 0.9]]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ExtractedDataChanges::fromRawFields(['ai_payload' => ['employee_first_name' => 'Mario']]))
        ->toThrow(InvalidArgumentException::class);
});
