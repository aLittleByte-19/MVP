<?php

use App\Models\AuditEvent;
use App\Models\PromptConfiguration;

test('operator can save a named prompt configuration (UC-19)', function () {
    $this->postJson('/api/v1/prompt-configurations', [
        'name' => 'Comunicazione ferie',
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('message', 'Configurazione salvata.')
        ->assertJsonPath('configuration.name', 'Comunicazione ferie')
        ->assertJsonPath('state.assistant.promptConfigurations.0.name', 'Comunicazione ferie');

    expect(PromptConfiguration::query()->where('name', 'Comunicazione ferie')->exists())->toBeTrue();
});

test('saving with an empty name assigns a progressive default label', function () {
    $this->postJson('/api/v1/prompt-configurations', [
        'name' => '',
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('configuration.name', 'Senza nome (1)');
});

test('saving with a name already in use for the tenant falls back to a default label', function () {
    PromptConfiguration::factory()->create(['tenant_id' => 'mvp-local-tenant', 'name' => 'Comunicazione ferie']);

    $this->postJson('/api/v1/prompt-configurations', [
        'name' => 'Comunicazione ferie',
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('configuration.name', 'Senza nome (1)');
});

test('the progressive default label skips labels already taken', function () {
    PromptConfiguration::factory()->create(['tenant_id' => 'mvp-local-tenant', 'name' => 'Senza nome (1)']);
    PromptConfiguration::factory()->create(['tenant_id' => 'mvp-local-tenant', 'name' => 'Senza nome (2)']);

    $this->postJson('/api/v1/prompt-configurations', [
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('configuration.name', 'Senza nome (3)');
});

test('a duplicate name from another tenant does not trigger the fallback label', function () {
    PromptConfiguration::factory()->create(['tenant_id' => 'another-tenant', 'name' => 'Comunicazione ferie']);

    $this->postJson('/api/v1/prompt-configurations', [
        'name' => 'Comunicazione ferie',
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('configuration.name', 'Comunicazione ferie');
});

test('an insufficient prompt is rejected when saving a configuration (UC-70)', function () {
    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/prompt-configurations', [
            'name' => 'Config incompleta',
            'prompt' => 'Corto',
            'tone' => 'Chiaro e diretto',
            'style' => 'Testo informativo',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(PromptConfiguration::query()->where('name', 'Config incompleta')->exists())->toBeFalse();
});

test('an invalid tone or style is rejected when saving a configuration', function () {
    $this->withHeader('Accept', 'application/json')
        ->postJson('/api/v1/prompt-configurations', [
            'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
            'tone' => 'Tono inventato',
            'style' => 'Testo informativo',
        ])
        ->assertUnprocessable();
});

test('saving a configuration records an audit event', function () {
    $this->postJson('/api/v1/prompt-configurations', [
        'name' => 'Comunicazione ferie',
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])->assertCreated();

    expect(AuditEvent::query()->where('event_type', 'mvp-prompt-configuration-saved')->count())->toBe(1);
});

test('operator can permanently delete a saved prompt configuration', function () {
    $configuration = PromptConfiguration::factory()->create(['tenant_id' => 'mvp-local-tenant']);

    $this->deleteJson("/api/v1/prompt-configurations/{$configuration->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Configurazione eliminata.')
        ->assertJsonStructure(['message', 'state']);

    expect(PromptConfiguration::query()->find($configuration->id))->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'mvp-prompt-configuration-deleted')->count())->toBe(1);
});

test('deleting a prompt configuration removes it from the state payload', function () {
    $configuration = PromptConfiguration::factory()->create(['tenant_id' => 'mvp-local-tenant']);

    $response = $this->deleteJson("/api/v1/prompt-configurations/{$configuration->id}")->assertOk();

    $ids = collect($response->json('state.assistant.promptConfigurations'))->pluck('id');
    expect($ids)->not->toContain($configuration->id);
});

test('delete prompt configuration endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $configuration = PromptConfiguration::factory()->create(['tenant_id' => 'mvp-local-tenant']);

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->deleteJson("/api/v1/prompt-configurations/{$configuration->id}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect(PromptConfiguration::query()->find($configuration->id))->not->toBeNull();
});

test('deleting a nonexistent prompt configuration returns 404', function () {
    $this->withHeader('Accept', 'application/json')
        ->deleteJson('/api/v1/prompt-configurations/999999')
        ->assertNotFound();
});
