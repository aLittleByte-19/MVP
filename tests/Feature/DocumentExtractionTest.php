<?php

use App\Exceptions\InvalidAiOutputException;
use App\Models\ExtractedData;
use App\Models\SubDocument;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Documents\Application\UseCases\ExtractSubDocumentFieldsService;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;

test('extractFields returns all expected keys on success', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Acme Srl',
                'document_date' => '2024-01-31',
                'document_type' => 'Cedolino',
                'description' => 'Cedolino gennaio 2024',
                'confidence_score' => 95,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $service = app(BedrockService::class);

    $fields = $service->extractFields($subDocument->file_path);

    $extracted = ExtractedData::create(array_merge(
        ['sub_document_id' => $subDocument->id],
        $fields,
    ));

    expect($extracted->employee_first_name)->toBe('Mario')
        ->and($extracted->employee_last_name)->toBe('Rossi')
        ->and($extracted->confidence_score)->toBe(95)
        ->and($extracted->company_name)->toBe('Acme Srl');

    $this->assertModelExists($extracted);
});

test('extractFields stores null for missing fields', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => null,
                'employee_last_name' => null,
                'company_name' => null,
                'document_date' => null,
                'document_type' => null,
                'description' => null,
                'confidence_score' => null,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $service = app(BedrockService::class);

    $fields = $service->extractFields($subDocument->file_path);

    $extracted = ExtractedData::create(array_merge(
        ['sub_document_id' => $subDocument->id],
        $fields,
    ));

    expect($extracted->confidence_score)->toBeNull()
        ->and($extracted->employee_first_name)->toBeNull();
});

test('extractFields throws RuntimeException on bedrock failure', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andThrow(new RuntimeException('Bedrock error'));
    });

    $subDocument = SubDocument::factory()->create();

    expect(function () use ($subDocument) {
        $service = app(BedrockService::class);
        $service->extractFields($subDocument->file_path);
    })->toThrow(RuntimeException::class);
});

test('extracted data is linked to its sub document', function () {
    $subDocument = SubDocument::factory()->create();
    $extracted = ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    expect($subDocument->extractedData->id)->toBe($extracted->id);
    expect($extracted->subDocument->id)->toBe($subDocument->id);
});

test('extracted data above confidence threshold is auto validated and preserves ai payload', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Acme Srl',
                'document_date' => '2026-01-31',
                'document_type' => 'Cedolino',
                'description' => 'Cedolino gennaio 2026',
                'confidence_score' => 90,
            ]);
    });

    $subDocument = SubDocument::factory()->create();

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->review_status)->toBe(ReviewStatus::AutoValidated)
        ->and($subDocument->fresh()->extractedData->ai_payload['confidence_score'])->toBe(90);
});

test('low confidence extraction is stored but marked as needs review', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);
    // Solo 2 dei 4 campi chiave estratti: la confidenza calcolata
    // (leggibilità OCR x completezza) scende sotto soglia anche con OCR alto.
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => null,
                'document_date' => null,
                'document_type' => 'Cedolino',
                'description' => 'Cedolino gennaio 2026',
                'confidence_score' => 95,
            ]);
    });

    $subDocument = SubDocument::factory()->create();

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->review_status)->toBe(ReviewStatus::NeedsReview)
        ->and($subDocument->fresh()->extractedData)->not->toBeNull();
});

test('invalid ai extraction output quarantines the sub document without persisted extracted data', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andThrow(new InvalidAiOutputException('extractFields', ['confidence_score: fuori range']));
    });

    $subDocument = SubDocument::factory()->create();

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->review_status)->toBe(ReviewStatus::Quarantined)
        ->and($subDocument->fresh()->error_message)->toContain('quarantena')
        ->and(ExtractedData::query()->where('sub_document_id', $subDocument->id)->exists())->toBeFalse();
});

test('manual upload metadata overrides AI extraction without rewriting ai payload', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'AI Company',
                'document_date' => '2024-06-15',
                'document_type' => 'lettera',
                'description' => 'Estratto AI',
                'confidence_score' => 90,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $subDocument->originalDocument->update([
        'manual_document_type' => 'cedolino',
        'manual_company_name' => 'Acme Srl',
        'manual_reference_month' => 3,
        'manual_reference_year' => 2026,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    $extracted = $subDocument->fresh()->extractedData;

    expect($extracted->document_type)->toBe('cedolino')
        ->and($extracted->company_name)->toBe('Acme Srl')
        ->and($extracted->document_date?->toDateString())->toBe('2026-03-01')
        ->and($extracted->ai_payload['document_type'])->toBe('lettera')
        ->and($extracted->ai_payload['company_name'])->toBe('AI Company')
        ->and($extracted->ai_payload['document_date'])->toBe('2024-06-15');
});

test('manual month alone is completed with the AI year', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'AI Company',
                'document_date' => '2024-06-15',
                'document_type' => 'lettera',
                'description' => 'Estratto AI',
                'confidence_score' => 90,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $subDocument->originalDocument->update([
        'manual_reference_month' => 3,
        'manual_reference_year' => null,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->document_date?->toDateString())->toBe('2024-03-01');
});

test('manual year alone is completed with the AI month', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'AI Company',
                'document_date' => '2024-06-15',
                'document_type' => 'lettera',
                'description' => 'Estratto AI',
                'confidence_score' => 90,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $subDocument->originalDocument->update([
        'manual_reference_month' => null,
        'manual_reference_year' => 2026,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->document_date?->toDateString())->toBe('2026-06-01');
});

test('partial manual date without usable AI date leaves the AI date untouched', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'AI Company',
                'document_date' => null,
                'document_type' => 'lettera',
                'description' => 'Estratto AI',
                'confidence_score' => 90,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $subDocument->originalDocument->update([
        'manual_reference_month' => 3,
        'manual_reference_year' => null,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->document_date)->toBeNull();
});

test('declared metadata does not stand in for fields the model failed to extract', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);
    // Azienda e data sono dichiarate in upload, quindi restano fuori dal calcolo:
    // l'AI e' valutata su nome e cognome, e ne trova uno solo. Contando anche i
    // campi dichiarati il cognome mancante sarebbe uno su quattro invece che
    // uno su due, e il documento passerebbe per quasi completo.
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => null,
                'company_name' => null,
                'document_date' => null,
                'document_type' => null,
                'description' => 'Estratto AI',
                'confidence_score' => 95,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $subDocument->originalDocument->update([
        'manual_company_name' => 'Acme Srl',
        'manual_reference_month' => 3,
        'manual_reference_year' => 2026,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    $extracted = $subDocument->fresh()->extractedData;

    // I campi dichiarati sono comunque persistiti, ma non concorrono al punteggio.
    // Il cognome manca, e un campo chiave assente azzera il punteggio: non c'e'
    // una riga OCR da cui farlo venire, quindi non c'e' confidenza da attribuirgli.
    expect($extracted->company_name)->toBe('Acme Srl')
        ->and($extracted->document_date?->toDateString())->toBe('2026-03-01')
        ->and($extracted->confidence_score)->toBe(0)
        ->and($subDocument->fresh()->review_status)->toBe(ReviewStatus::NeedsReview);
});

test('declaring metadata does not penalise a model that extracts what is left to it', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);
    // Stessi campi dichiarati del caso precedente, ma qui l'AI trova entrambi i
    // campi rimasti a suo carico: la confidenza resta quella della scansione.
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => null,
                'document_date' => null,
                'document_type' => null,
                'description' => 'Estratto AI',
                'confidence_score' => 95,
            ]);
    });

    $subDocument = SubDocument::factory()->create();
    $subDocument->originalDocument->update([
        'manual_company_name' => 'Acme Srl',
        'manual_reference_month' => 3,
        'manual_reference_year' => 2026,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->confidence_score)->toBe(98)
        ->and($subDocument->fresh()->review_status)->toBe(ReviewStatus::AutoValidated);
});

/**
 * Pagina OCR in cui il contorno e' nitido e una sola riga no: e' il caso che
 * la media di pagina non sapeva distinguere (vedi ADR 0013).
 *
 * @param  array<int, array{0: string, 1: float}>  $righe
 * @return array<int, array<string, mixed>>
 */
function mvpPaginaOcr(array $righe, int $numero = 1): array
{
    $blocks = array_map(fn (array $riga): array => ['text' => $riga[0], 'confidence' => $riga[1]], $righe);
    $confidenze = array_column($blocks, 'confidence');

    return [[
        'page' => $numero,
        'text' => implode("\n", array_column($blocks, 'text')),
        'confidenceAvg' => array_sum($confidenze) / count($confidenze),
        'blocks' => $blocks,
    ]];
}

test('an unreadable key field sends the document to review even on a clean page', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')->once()->andReturn([
            'employee_first_name' => 'Giulia',
            'employee_last_name' => 'Ferraris',
            'company_name' => 'Meridiana Logistica S.p.A.',
            'document_date' => '2026-03-31',
            'document_type' => 'Cedolino paga',
            'description' => 'Cedolino di marzo',
            'confidence_score' => 95,
        ]);
    });

    $subDocument = SubDocument::factory()->create(['start_page' => 1, 'end_page' => 1]);
    $subDocument->originalDocument->update([
        'ocr_pages' => mvpPaginaOcr([
            ['Meridiana Logistica S.p.A.', 99.4],
            ['Cedolino paga', 99.7],
            ['Data di emissione: 31/03/2026', 99.1],
            // Il cognome e' l'unica riga rovinata della pagina.
            ['Giulia Ferraris', 41.2],
            ['Totale competenze', 99.6],
        ]),
        'ocr_confidence_avg' => 87.8,
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    // Il punteggio e' quello del campo piu' debole, non la media della pagina.
    expect($subDocument->fresh()->extractedData->confidence_score)->toBe(41)
        ->and($subDocument->fresh()->review_status)->toBe(ReviewStatus::NeedsReview);
});

test('the score is the weakest key field and the per-field confidences are kept', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')->once()->andReturn([
            'employee_first_name' => 'Giulia',
            'employee_last_name' => 'Ferraris',
            'company_name' => 'Meridiana Logistica S.p.A.',
            'document_date' => '2026-03-31',
            'document_type' => 'Cedolino paga',
            'description' => 'Cedolino di marzo',
            'employee_id' => 'MTR-10428',
            'confidence_score' => 95,
        ]);
    });

    $subDocument = SubDocument::factory()->create(['start_page' => 1, 'end_page' => 1]);
    $subDocument->originalDocument->update([
        'ocr_pages' => mvpPaginaOcr([
            ['Meridiana Logistica S.p.A.', 96.0],
            ['Giulia Ferraris', 98.5],
            ['Data di emissione: 31/03/2026', 91.0],
            ['Matricola MTR-10428', 74.0],
        ]),
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);
    $extracted = $subDocument->fresh()->extractedData;

    // La matricola non e' un campo chiave: pesa nel dettaglio, non nel punteggio.
    expect($extracted->confidence_score)->toBe(91)
        ->and($subDocument->fresh()->review_status)->toBe(ReviewStatus::AutoValidated)
        // toEqual e non toBe: un decimale tondo torna dal JSON come intero.
        ->and($extracted->field_confidences['employee_last_name'])->toEqual(98.5)
        ->and($extracted->field_confidences['company_name'])->toEqual(96.0)
        ->and($extracted->field_confidences['employee_id'])->toEqual(74.0)
        // Nessuna riga porta questo indirizzo: il campo non e' rintracciabile.
        ->and($extracted->field_confidences['recipient_email'])->toBeNull();
});

test('a fiscal code below its own threshold holds back the automatic validation', function () {
    // Il Capitolato chiede «mapping CF >= 99%» come criterio a se' stante: un
    // codice fiscale letto male assegna il documento a un'altra persona.
    config([
        'services.bedrock.mvp_confidence_threshold' => 80,
        'services.bedrock.mvp_fiscal_code_confidence_threshold' => 99,
    ]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')->once()->andReturn([
            'employee_first_name' => 'Giulia',
            'employee_last_name' => 'Ferraris',
            'company_name' => 'Meridiana Logistica S.p.A.',
            'document_date' => '2026-03-31',
            'document_type' => 'Cedolino paga',
            'description' => 'Cedolino di marzo',
            'fiscal_code' => 'FRRGLI88M52L781R',
            'confidence_score' => 95,
        ]);
    });

    $subDocument = SubDocument::factory()->create(['start_page' => 1, 'end_page' => 1]);
    $subDocument->originalDocument->update([
        'ocr_pages' => mvpPaginaOcr([
            ['Meridiana Logistica S.p.A.', 99.4],
            ['Giulia Ferraris', 99.5],
            ['Data di emissione: 31/03/2026', 99.2],
            // Tutti i campi chiave sono sopra soglia: solo il codice fiscale no.
            ['FRRGLI88M52L781R', 92.0],
        ]),
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->confidence_score)->toBe(99)
        ->and($subDocument->fresh()->review_status)->toBe(ReviewStatus::NeedsReview);
});

test('a document without a fiscal code is not penalised for a field it does not carry', function () {
    config([
        'services.bedrock.mvp_confidence_threshold' => 80,
        'services.bedrock.mvp_fiscal_code_confidence_threshold' => 99,
    ]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('extractFields')->once()->andReturn([
            'employee_first_name' => 'Giulia',
            'employee_last_name' => 'Ferraris',
            'company_name' => 'Meridiana Logistica S.p.A.',
            'document_date' => '2026-03-31',
            'document_type' => 'Comunicazione',
            'description' => 'Comunicazione interna',
            'fiscal_code' => null,
            'confidence_score' => 95,
        ]);
    });

    $subDocument = SubDocument::factory()->create(['start_page' => 1, 'end_page' => 1]);
    $subDocument->originalDocument->update([
        'ocr_pages' => mvpPaginaOcr([
            ['Meridiana Logistica S.p.A.', 99.4],
            ['Giulia Ferraris', 99.5],
            ['Data di emissione: 31/03/2026', 99.2],
        ]),
    ]);

    app(ExtractSubDocumentFieldsService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->review_status)->toBe(ReviewStatus::AutoValidated);
});
