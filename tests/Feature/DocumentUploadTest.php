<?php

use App\Copilot\Ai\BedrockService;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;

test('uploading a pdf creates an original document with pending status', function () {
    $originalDocument = OriginalDocument::create([
        'file_path' => 'documents/originals/test.pdf',
        'original_filename' => 'test.pdf',
        'processing_status' => ProcessingStatus::Pending,
    ]);

    expect($originalDocument->processing_status)->toBe(ProcessingStatus::Pending);
    $this->assertModelExists($originalDocument);
});

test('successful split creates sub documents linked to original', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([
                ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 2],
                ['employee_name' => 'Anna Bianchi', 'start_page' => 3, 'end_page' => 4],
            ]);
    });

    $original = OriginalDocument::factory()->create();

    $service = app(BedrockService::class);
    $segments = $service->splitDocument($original->ocr_text, 4, 'test-nonce');

    foreach ($segments as $segment) {
        SubDocument::create([
            'original_document_id' => $original->id,
            'file_path' => "documents/sub/{$original->id}_{$segment['employee_name']}.pdf",
            'start_page' => $segment['start_page'],
            'end_page' => $segment['end_page'],
        ]);
    }

    expect(SubDocument::where('original_document_id', $original->id)->count())->toBe(2);
});

test('original document status is set to failed when bedrock split fails', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andThrow(new RuntimeException('Bedrock error'));
    });

    $original = OriginalDocument::factory()->create();

    try {
        $service = app(BedrockService::class);
        $service->splitDocument($original->ocr_text, 4, 'test-nonce');
    } catch (RuntimeException) {
        $original->update(['processing_status' => ProcessingStatus::Failed]);
    }

    expect($original->fresh()->processing_status)->toBe(ProcessingStatus::Failed);
});

test('split returns empty array when no employees detected', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([]);
    });

    $original = OriginalDocument::factory()->create();

    $service = app(BedrockService::class);
    $segments = $service->splitDocument($original->ocr_text, 4, 'test-nonce');

    expect($segments)->toBeArray()->toBeEmpty();
    expect(SubDocument::where('original_document_id', $original->id)->count())->toBe(0);
});

test('uploading a duplicate filename stores both as separate records', function () {
    $doc1 = OriginalDocument::create([
        'file_path' => 'documents/originals/cedolini.pdf',
        'original_filename' => 'cedolini.pdf',
        'processing_status' => ProcessingStatus::Pending,
    ]);

    $doc2 = OriginalDocument::create([
        'file_path' => 'documents/originals/cedolini_2.pdf',
        'original_filename' => 'cedolini.pdf',
        'processing_status' => ProcessingStatus::Pending,
    ]);

    expect(OriginalDocument::where('original_filename', 'cedolini.pdf')->count())->toBe(2);
    expect($doc1->id)->not->toBe($doc2->id);
});
