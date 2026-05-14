<?php

use App\Poc\Enums\CommunicationStatus;
use App\Poc\Models\Communication;
use App\Poc\Services\BedrockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('successful generation creates a draft communication record with all fields', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->andReturn(['title' => 'Titolo generato', 'body' => 'Corpo generato']);
    });

    $service = app(BedrockService::class);
    $result = $service->generateCommunication('Il mio prompt', 'formal', 'newsletter');

    $communication = Communication::create([
        'prompt' => 'Il mio prompt',
        'tone' => 'formal',
        'style' => 'newsletter',
        'generated_title' => $result['title'],
        'generated_body' => $result['body'],
        'status' => CommunicationStatus::Draft,
    ]);

    expect($communication->status)->toBe(CommunicationStatus::Draft)
        ->and($communication->prompt)->toBe('Il mio prompt')
        ->and($communication->generated_title)->toBe('Titolo generato')
        ->and($communication->generated_body)->toBe('Corpo generato');

    $this->assertModelExists($communication);
});

test('default status is draft on communication creation', function () {
    $communication = Communication::factory()->draft()->create();

    expect($communication->status)->toBe(CommunicationStatus::Draft);
});

test('draft communication can be updated to discarded', function () {
    $communication = Communication::factory()->draft()->create();

    $communication->update(['status' => CommunicationStatus::Discarded]);

    expect($communication->fresh()->status)->toBe(CommunicationStatus::Discarded);
});

test('failed bedrock call does not create a communication record', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->andThrow(new RuntimeException('Bedrock error'));
    });

    $initialCount = Communication::count();

    try {
        $service = app(BedrockService::class);
        $service->generateCommunication('prompt', 'formal', 'newsletter');
    } catch (RuntimeException) {
        // expected — no record should be created
    }

    expect(Communication::count())->toBe($initialCount);
});
