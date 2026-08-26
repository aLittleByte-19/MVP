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
    // Prima era un middleware: un'eccezione lanciata da $next($request) (qui,
    // ValidationException) saltava tutto il codice dopo quella chiamata, e la
    // richiesta spariva senza lasciare traccia — proprio il caso che Errors
    // dovrebbe intercettare. Da RequestHandled (ADR 0015) la risposta e' gia'
    // stata tradotta dall'exception handler, quindi arriva sempre.
    $metrics = Mockery::mock(EmfMetricsRecorder::class);
    $metrics->shouldReceive('put')->once();
    app()->instance(EmfMetricsRecorder::class, $metrics);

    $this->postJson('/api/v1/communications', [])->assertStatus(422);
});
