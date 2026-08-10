<?php

use App\Exceptions\InvalidAiOutputException;
use App\Models\ExtractedData;
use App\Models\SubDocument;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Documents\Application\UseCases\ProcessDocumentService;
use App\Mvp\Documents\Enums\ReviewStatus;

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->document_date)->toBeNull();
});

test('declared metadata does not stand in for fields the model failed to extract', function () {
    config(['services.bedrock.mvp_confidence_threshold' => 80]);
    // Azienda e data sono dichiarate in upload, quindi restano fuori dal calcolo:
    // l'AI e' valutata su nome e cognome, e ne trova uno solo. Contando anche i
    // campi dichiarati la completezza risulterebbe 3 su 4 invece di 1 su 2.
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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

    $extracted = $subDocument->fresh()->extractedData;

    // I campi dichiarati sono comunque persistiti, ma non concorrono al punteggio.
    expect($extracted->company_name)->toBe('Acme Srl')
        ->and($extracted->document_date?->toDateString())->toBe('2026-03-01')
        ->and($extracted->confidence_score)->toBe(49)
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

    app(ProcessDocumentService::class)->extractAndSaveFields($subDocument->id);

    expect($subDocument->fresh()->extractedData->confidence_score)->toBe(98)
        ->and($subDocument->fresh()->review_status)->toBe(ReviewStatus::AutoValidated);
});
