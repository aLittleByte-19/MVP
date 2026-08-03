<?php

use App\Models\AuditEvent;
use App\Models\Communication;

test('redattore can add a communication to favorites', function () {
    $communication = Communication::factory()->draft()->create(['is_favorite' => false]);

    $this->postJson("/api/v1/communications/{$communication->id}/favorite")
        ->assertOk()
        ->assertJsonPath('communication.isFavorite', true)
        ->assertJsonPath('message', 'Generazione aggiunta ai preferiti.');

    expect($communication->fresh()->is_favorite)->toBeTrue()
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-favorited')->count())->toBe(1);
});

test('adding an already favorite communication to favorites fails validation', function () {
    $communication = Communication::factory()->draft()->favorite()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/favorite")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect($communication->fresh()->is_favorite)->toBeTrue();
});

test('redattore can remove a communication from favorites', function () {
    $communication = Communication::factory()->draft()->favorite()->create();

    $this->deleteJson("/api/v1/communications/{$communication->id}/favorite")
        ->assertOk()
        ->assertJsonPath('communication.isFavorite', false)
        ->assertJsonPath('message', 'Generazione rimossa dai preferiti.');

    expect($communication->fresh()->is_favorite)->toBeFalse()
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-unfavorited')->count())->toBe(1);
});

test('removing a non favorite communication from favorites fails validation', function () {
    $communication = Communication::factory()->draft()->create(['is_favorite' => false]);

    $this->deleteJson("/api/v1/communications/{$communication->id}/favorite")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect($communication->fresh()->is_favorite)->toBeFalse();
});

test('favorite endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->create(['is_favorite' => false]);

    $this->postJson("/api/v1/communications/{$communication->id}/favorite", [], [
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($communication->fresh()->is_favorite)->toBeFalse();
});

test('unfavorite endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->favorite()->create();

    $this->deleteJson("/api/v1/communications/{$communication->id}/favorite", [], [
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($communication->fresh()->is_favorite)->toBeTrue();
});

test('assistant history reflects favorite state', function () {
    $communication = Communication::factory()->draft()->favorite()->create();

    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonPath('assistant.history.0.isFavorite', true)
        ->assertJsonPath('assistant.history.0.id', $communication->id);
});
