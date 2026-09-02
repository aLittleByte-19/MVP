<?php

use App\Mvp\Observability\EmfMetricsRecorder;

test('a request emits request-count, latency and error-rate metrics', function () {
    $metrics = Mockery::mock(EmfMetricsRecorder::class);
    $metrics->shouldReceive('put')
        ->once()
        ->with(
            Mockery::on(fn (array $dimensions) => isset($dimensions['Route'], $dimensions['Method'])),
            Mockery::on(fn (array $recorded) => array_keys($recorded) === ['RequestCount', 'Latency', 'Errors']
                && $recorded['RequestCount'] === ['value' => 1, 'unit' => 'Count']
                && $recorded['Errors'] === ['value' => 0, 'unit' => 'Count']
                && $recorded['Latency']['unit'] === 'Milliseconds'),
            Mockery::on(fn (array $properties) => array_key_exists('request_id', $properties)),
        );
    app()->instance(EmfMetricsRecorder::class, $metrics);

    $this->getJson('/health')->assertOk();
});

test('a request that fails validation is still recorded (listener sees the exception-translated response)', function () {
    // RequestHandled (ADR 0015) arriva dopo che l'exception handler ha gia'
    // tradotto la ValidationException in risposta, quindi il listener la vede sempre.
    $metrics = Mockery::mock(EmfMetricsRecorder::class);
    $metrics->shouldReceive('put')->once();
    app()->instance(EmfMetricsRecorder::class, $metrics);

    $this->postJson('/api/v1/communications', [])->assertStatus(422);
});
