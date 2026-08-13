<?php

use App\Models\Communication;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;

test('approved is a valid communication status', function () {
    $communication = Communication::factory()->draft()->create();

    $communication->update(['status' => CommunicationStatus::Approved]);

    expect($communication->fresh()->status)->toBe(CommunicationStatus::Approved);
});

test('approved status has correct label and color', function () {
    expect(CommunicationStatus::Approved->label())->toBe('Approvata')
        ->and(CommunicationStatus::Approved->color())->toBe('success');
});
