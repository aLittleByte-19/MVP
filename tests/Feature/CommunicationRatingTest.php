<?php

use App\Http\Requests\Copilot\RateCommunicationRequest;
use App\Models\Copilot\AuditEvent;
use App\Models\Copilot\Communication;
use Tests\Support\OpenApiSpec;

test('POST /api/v1/communications/{id}/rating stores score and optional comment', function () {
    $communication = Communication::factory()->draft()->create();

    $response = $this->postJson("/api/v1/communications/{$communication->id}/rating", [
        'rating' => 4,
        'comment' => 'Bozza chiara e utile.',
    ])->assertOk()
        ->assertJsonPath('message', 'Valutazione registrata con successo.')
        ->assertJsonPath('communication.rating', 4)
        ->assertJsonPath('communication.ratingComment', 'Bozza chiara e utile.');

    $fresh = $communication->fresh();

    expect($fresh->rating)->toBe(4)
        ->and($fresh->rating_comment)->toBe('Bozza chiara e utile.')
        ->and($fresh->rated_at)->not->toBeNull()
        ->and($fresh->rated_by)->toBe('mvp-local-user')
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-rated')->count())->toBe(1);

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/rating',
        'post',
        '200',
    );
});

test('POST /api/v1/communications/{id}/rating rejects a second rating for the same draft', function () {
    $communication = Communication::factory()->draft()->rated(5)->create();

    $this->postJson("/api/v1/communications/{$communication->id}/rating", [
        'rating' => 2,
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect($communication->fresh()->rating)->toBe(5);
});

test('POST /api/v1/communications/{id}/rating rejects comment longer than max length', function () {
    $communication = Communication::factory()->draft()->create();

    $response = $this->postJson("/api/v1/communications/{$communication->id}/rating", [
        'rating' => 3,
        'comment' => str_repeat('a', RateCommunicationRequest::COMMENT_MAX_LENGTH + 1),
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect($response->json('error.fields.comment.0'))->toContain('lunghezza massima')
        ->and($communication->fresh()->rating)->toBeNull();
});

test('POST /api/v1/communications/{id}/rating forbids rating another tenant draft', function () {
    $communication = Communication::factory()->draft()->create([
        'tenant_id' => 'altro-tenant',
    ]);

    $this->postJson("/api/v1/communications/{$communication->id}/rating", [
        'rating' => 5,
    ])->assertForbidden();
});
