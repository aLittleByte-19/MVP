<?php

test('health endpoint reports live process and correlation headers', function () {
    $this->getJson('/health', [
        'X-Correlation-ID' => 'test-correlation-123',
    ])
        ->assertOk()
        ->assertHeader('X-Correlation-ID', 'test-correlation-123')
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'service', 'timestamp']);
});

test('readiness endpoint reports required dependency checks', function () {
    config([
        'queue.default' => 'sync',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'filesystems.default' => 'local',
    ]);

    $this->getJson('/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonStructure([
            'checks' => ['config', 'database', 'redis', 'queue'],
        ]);
});

test('versioned api exposes existing poc state contract', function () {
    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonStructure(['assistant', 'copilot']);
});

test('versioned api validation errors use a stable envelope', function () {
    $this->postJson('/api/v1/communications', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'requestId', 'correlationId', 'fields'],
        ]);
});
