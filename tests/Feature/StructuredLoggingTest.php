<?php

use Monolog\Formatter\JsonFormatter;

test('the stderr channel is configured for structured JSON logs', function () {
    expect(config('logging.channels.stderr.formatter'))->toBe(JsonFormatter::class);
});

test('every response carries the request and correlation identifiers used to structure the logs', function () {
    $this->getJson('/health', ['X-Correlation-ID' => 'structured-logging-test'])
        ->assertOk()
        ->assertHeader('X-Correlation-ID', 'structured-logging-test')
        ->assertHeader('X-Request-ID');
});
