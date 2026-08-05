<?php

use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Support\MvpStateService;

test('document exposes recipient email when extracted data has one', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create([
        'sub_document_id' => $subDocument->id,
        'recipient_email' => 'consulente@example.com',
    ]);

    $document = app(MvpStateService::class)->document($subDocument->fresh(['originalDocument', 'extractedData']));

    expect($document['recipientEmail'])->toBe('consulente@example.com');
});

test('document exposes null recipient email when not available', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->withNullFields()->create(['sub_document_id' => $subDocument->id]);

    $document = app(MvpStateService::class)->document($subDocument->fresh(['originalDocument', 'extractedData']));

    expect($document['recipientEmail'])->toBeNull();
});

test('document exposes the original document upload timestamp, not the sub document one', function () {
    $original = OriginalDocument::factory()->create(['created_at' => '2026-01-15 09:30:00']);
    $subDocument = SubDocument::factory()->create([
        'original_document_id' => $original->id,
        'created_at' => '2026-01-16 18:00:00',
    ]);
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $document = app(MvpStateService::class)->document($subDocument->fresh(['originalDocument', 'extractedData']));

    expect($document['uploadedAt'])->toBe('15/01/2026 09:30');
});
