<?php

use App\Enums\SendStatus;
use App\Models\ExtractedData;
use App\Models\SubDocument;
use App\Services\BedrockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

test('sub document default send status is pending', function () {
    $subDocument = SubDocument::factory()->create();

    expect($subDocument->send_status)->toBe(SendStatus::Pending);
});

test('sub document send status can be updated to sent', function () {
    $subDocument = SubDocument::factory()->create();
    $subDocument->update(['send_status' => SendStatus::Sent]);

    expect($subDocument->fresh()->send_status)->toBe(SendStatus::Sent);
});

test('extracted data is linked to its sub document', function () {
    $subDocument = SubDocument::factory()->create();
    $extracted = ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    expect($subDocument->extractedData->id)->toBe($extracted->id);
    expect($extracted->subDocument->id)->toBe($subDocument->id);
});
