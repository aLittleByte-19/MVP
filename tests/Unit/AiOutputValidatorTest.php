<?php

use App\Copilot\Ai\AiOutputValidator;
use App\Exceptions\Copilot\InvalidAiOutputException;

test('extract fields validator accepts a complete normalized payload', function () {
    $result = app(AiOutputValidator::class)->validateExtractFields([
        'employee_first_name' => ' Mario ',
        'employee_last_name' => 'Rossi',
        'company_name' => 'Acme',
        'document_date' => '2026-02-28',
        'document_type' => 'Cedolino',
        'description' => 'Cedolino mensile',
        'confidence_score' => 80,
    ]);

    expect($result)
        ->toMatchArray([
            'employee_first_name' => 'Mario',
            'employee_last_name' => 'Rossi',
            'confidence_score' => 80,
        ]);
});

test('extract fields validator normalizes missing keys to null and ignores extra keys', function () {
    $result = app(AiOutputValidator::class)->validateExtractFields([
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'reasoning' => 'il modello aggiunge note non richieste',
    ]);

    expect($result)
        ->toMatchArray([
            'employee_first_name' => 'Mario',
            'employee_last_name' => 'Rossi',
            'company_name' => null,
            'document_date' => null,
            'document_type' => null,
            'description' => null,
            'confidence_score' => null,
        ])
        ->not->toHaveKey('reasoning');
});

test('extract fields validator rejects wrong types and confidence outside range', function () {
    // Confidence come stringa: tipo errato, non coercibile silenziosamente.
    expect(fn () => app(AiOutputValidator::class)->validateExtractFields([
        'confidence_score' => '85',
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateExtractFields([
        'confidence_score' => 101,
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateExtractFields([
        'confidence_score' => -1,
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateExtractFields([
        'document_date' => '31/01/2026',
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateExtractFields([
        'description' => str_repeat('a', 2001),
    ]))->toThrow(InvalidAiOutputException::class);
});

test('extract fields validator accepts boundary confidence values', function () {
    expect(app(AiOutputValidator::class)->validateExtractFields(['confidence_score' => 0])['confidence_score'])->toBe(0)
        ->and(app(AiOutputValidator::class)->validateExtractFields(['confidence_score' => 100])['confidence_score'])->toBe(100);
});

test('extract fields validator rejects non-object payloads', function () {
    expect(fn () => app(AiOutputValidator::class)->validateExtractFields(['solo', 'una', 'lista']))
        ->toThrow(InvalidAiOutputException::class);
});

test('extract fields validator rejects impossible dates', function () {
    expect(fn () => app(AiOutputValidator::class)->validateExtractFields([
        'document_date' => '2026-02-31',
    ]))->toThrow(InvalidAiOutputException::class);
});

test('split document validator rejects invalid page ranges and duplicate ranges', function () {
    expect(fn () => app(AiOutputValidator::class)->validateSplitDocument([
        ['employee_name' => 'Mario Rossi', 'start_page' => 4, 'end_page' => 2],
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateSplitDocument([
        ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 2],
        ['employee_name' => 'Anna Bianchi', 'start_page' => 1, 'end_page' => 2],
    ]))->toThrow(InvalidAiOutputException::class);
});

test('split document validator rejects missing keys and non-integer pages', function () {
    expect(fn () => app(AiOutputValidator::class)->validateSplitDocument([
        ['start_page' => 1, 'end_page' => 2],
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateSplitDocument([
        ['employee_name' => 'Mario Rossi', 'start_page' => '1', 'end_page' => 2],
    ]))->toThrow(InvalidAiOutputException::class);

    expect(fn () => app(AiOutputValidator::class)->validateSplitDocument([
        ['employee_name' => 'Mario Rossi', 'start_page' => 0, 'end_page' => 2],
    ]))->toThrow(InvalidAiOutputException::class);
});

test('split document validator accepts an empty array as a valid model answer', function () {
    // "Nessun dipendente distinto" e' una risposta legittima del classificatore:
    // il fallimento di business (zero segmenti) viene gestito a valle.
    expect(app(AiOutputValidator::class)->validateSplitDocument([]))->toBe([]);
});

test('split document validator ignores extra keys per segment', function () {
    $segments = app(AiOutputValidator::class)->validateSplitDocument([
        ['employee_name' => ' Mario Rossi ', 'start_page' => 1, 'end_page' => 2, 'confidence' => 'alta'],
    ]);

    expect($segments)->toBe([
        ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 2],
    ]);
});

test('generate communication validator requires title and body but ignores extra keys', function () {
    expect(fn () => app(AiOutputValidator::class)->validateGenerateCommunication([
        'title' => 'Titolo senza corpo',
    ]))->toThrow(InvalidAiOutputException::class);

    expect(app(AiOutputValidator::class)->validateGenerateCommunication([
        'title' => ' Titolo ',
        'body' => 'Corpo',
        'send_now' => true,
    ]))->toBe(['title' => 'Titolo', 'body' => 'Corpo']);
});
