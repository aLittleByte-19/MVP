<?php

use App\Mvp\Documents\Domain\Support\FieldConfidence;

/**
 * Test di dominio puro: l'attribuzione della confidenza a un campo e'
 * aritmetica sul testo OCR, e si prova senza Textract e senza database.
 */

/**
 * Righe OCR di una pagina di cedolino: il contorno e' nitido, il cognome no.
 * E' il caso che la media di pagina non sa distinguere (vedi ADR 0013).
 *
 * @return array<int, array{text: string, confidence: float|null}>
 */
function paginaConCognomeIlleggibile(): array
{
    return [
        ['text' => 'Meridiana Logistica S.p.A.', 'confidence' => 99.4],
        ['text' => 'Cedolino paga', 'confidence' => 99.7],
        ['text' => 'DIPENDENTE', 'confidence' => 99.8],
        ['text' => 'Giulia Ferraris', 'confidence' => 41.2],
        ['text' => 'Retribuzione ordinaria', 'confidence' => 99.5],
        ['text' => 'Totale competenze', 'confidence' => 99.6],
    ];
}

test('a field takes the confidence of the line that carries it', function () {
    expect(FieldConfidence::forValue('Ferraris', paginaConCognomeIlleggibile()))->toBe(41.2)
        ->and(FieldConfidence::forValue('Meridiana Logistica S.p.A.', paginaConCognomeIlleggibile()))->toBe(99.4);
});

test('an unreadable field stays low even when the rest of the page is clean', function () {
    // La media delle righe supera 90: e' proprio il valore che mandava in
    // validazione automatica un documento col destinatario sbagliato.
    $blocks = paginaConCognomeIlleggibile();
    $media = array_sum(array_column($blocks, 'confidence')) / count($blocks);

    expect($media)->toBeGreaterThan(89.0)
        ->and(FieldConfidence::forValue('Giulia Ferraris', $blocks))->toBe(41.2);
});

test('matching ignores case, accents and punctuation', function () {
    $blocks = [['text' => 'SOCIETA AGRICOLA SANT ELENA SRL', 'confidence' => 96.0]];

    expect(FieldConfidence::forValue('Società Agricola Sant\'Elena S.r.l.', $blocks))->toBe(96.0);
});

test('a value split across lines takes the weakest of its parts', function () {
    $blocks = [
        ['text' => 'Consorzio Nazionale', 'confidence' => 98.0],
        ['text' => 'Trasporti Integrati', 'confidence' => 62.5],
    ];

    expect(FieldConfidence::forValue('Consorzio Nazionale Trasporti Integrati', $blocks))->toBe(62.5);
});

test('a value the document does not carry is not traceable', function () {
    $blocks = [['text' => 'Meridiana Logistica S.p.A.', 'confidence' => 99.4]];

    expect(FieldConfidence::forValue('Acme Incorporated', $blocks))->toBeNull()
        ->and(FieldConfidence::forValue(null, $blocks))->toBeNull()
        ->and(FieldConfidence::forValue('Ferraris', []))->toBeNull();
});

test('lines without a declared confidence do not answer for a field', function () {
    $blocks = [['text' => 'Giulia Ferraris', 'confidence' => null]];

    expect(FieldConfidence::forValue('Ferraris', $blocks))->toBeNull();
});

test('a date is looked up in the renderings the document actually uses', function () {
    // Il modello normalizza a YYYY-MM-DD, il foglio scrive in italiano.
    expect(FieldConfidence::forDate('2026-03-31', [['text' => 'Data di emissione: 31/03/2026', 'confidence' => 97.1]]))->toBe(97.1)
        ->and(FieldConfidence::forDate('2026-03-31', [['text' => '31 marzo 2026', 'confidence' => 88.0]]))->toBe(88.0)
        ->and(FieldConfidence::forDate('2026-03-01', [['text' => 'Periodo di riferimento Marzo 2026', 'confidence' => 93.3]]))->toBe(93.3)
        ->and(FieldConfidence::forDate('2026-03-31', [['text' => '2026-03-31', 'confidence' => 95.0]]))->toBe(95.0);
});

test('a date the document does not carry is not traceable', function () {
    expect(FieldConfidence::forDate('2026-03-31', [['text' => 'Cedolino paga', 'confidence' => 99.0]]))->toBeNull()
        ->and(FieldConfidence::forDate(null, [['text' => 'x', 'confidence' => 99.0]]))->toBeNull();
});
