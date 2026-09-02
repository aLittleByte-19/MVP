<?php

use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\DB;

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

test('readiness configuration check lists every missing setting', function () {
    config([
        'app.key' => '',
        'filesystems.default' => 's3',
        'filesystems.disks.s3.bucket' => '',
        'queue.default' => 'sqs',
        'queue.connections.sqs.key' => null,
        'queue.connections.sqs.secret' => null,
        'queue.connections.sqs.queue' => '',
        'cache.default' => 'array',
        'session.driver' => 'array',
        'database.redis.client' => null,
    ]);

    $controller = new HealthController;
    $configuration = new ReflectionMethod($controller, 'checkConfiguration');
    $queue = new ReflectionMethod($controller, 'checkQueue');

    expect($configuration->invoke($controller))->toBe([
        'status' => 'failed',
        'message' => 'Missing required configuration: APP_KEY, AWS_BUCKET, SQS_QUEUE',
    ])->and($queue->invoke($controller))->toBe([
        'status' => 'failed',
        'message' => 'SQS credentials are not configured for readiness checks.',
    ]);
});

test('readiness reports dependency errors without leaking credentials', function () {
    DB::shouldReceive('select')
        ->once()
        ->andThrow(new RuntimeException('AWS_SECRET_ACCESS_KEY=super-secret database unavailable'));

    config([
        'filesystems.default' => 'local',
        'queue.default' => 'sqs',
        'queue.connections.sqs' => [
            'driver' => 'sqs',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'token' => null,
            'prefix' => 'http://127.0.0.1:9/000000000000',
            'queue' => 'readiness-test',
            'suffix' => null,
            'region' => 'eu-north-1',
            'endpoint' => 'http://127.0.0.1:9',
            'after_commit' => false,
        ],
        'cache.default' => 'array',
        'session.driver' => 'array',
        'database.redis.client' => null,
    ]);

    $response = $this->getJson('/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'not_ready')
        ->assertJsonPath('checks.config.status', 'ok')
        ->assertJsonPath('checks.database.status', 'failed')
        ->assertJsonPath('checks.redis.status', 'skipped')
        ->assertJsonPath('checks.queue.status', 'failed');

    expect($response->json('checks.database.message'))
        ->toContain('AWS_SECRET_ACCESS_KEY=[redacted]')
        ->not->toContain('super-secret');
});

test('versioned api exposes existing mvp state contract', function () {
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
