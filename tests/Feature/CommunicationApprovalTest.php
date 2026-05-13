<?php

use App\Poc\Enums\CommunicationStatus;
use App\Poc\Models\Communication;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('approved is a valid communication status', function () {
    $communication = Communication::factory()->draft()->create();

    $communication->update(['status' => CommunicationStatus::Approved]);

    expect($communication->fresh()->status)->toBe(CommunicationStatus::Approved);
});

test('approved status has correct label and color', function () {
    expect(CommunicationStatus::Approved->label())->toBe('Approvata')
        ->and(CommunicationStatus::Approved->color())->toBe('success');
});
