<?php

use App\Mvp\Observability\EmfMetricsRecorder;
use Illuminate\Support\Facades\Log;

test('a metric is emitted as a single EMF-formatted line on the metrics channel', function () {
    Log::shouldReceive('channel')->once()->with('metrics')->andReturnSelf();
    Log::shouldReceive('info')->once()->with(Mockery::on(function (string $line) {
        $payload = json_decode($line, true);

        return $payload['_aws']['CloudWatchMetrics'][0]['Namespace'] === 'MVP/Test'
            && $payload['_aws']['CloudWatchMetrics'][0]['Dimensions'] === [['Route']]
            && $payload['_aws']['CloudWatchMetrics'][0]['Metrics'][0] === ['Name' => 'RequestCount', 'Unit' => 'Count']
            && $payload['Route'] === 'api.health'
            && $payload['RequestCount'] === 1
            && $payload['request_id'] === 'req-1';
    }));

    (new EmfMetricsRecorder(enabled: true, namespace: 'MVP/Test'))->put(
        dimensions: ['Route' => 'api.health'],
        metrics: ['RequestCount' => ['value' => 1, 'unit' => 'Count']],
        properties: ['request_id' => 'req-1'],
    );
});

test('nothing is written when the recorder is disabled', function () {
    Log::shouldReceive('channel')->never();

    (new EmfMetricsRecorder(enabled: false))->put(
        dimensions: ['Route' => 'api.health'],
        metrics: ['RequestCount' => ['value' => 1, 'unit' => 'Count']],
    );
});

test('nothing is written when there are no metrics to report', function () {
    Log::shouldReceive('channel')->never();

    (new EmfMetricsRecorder(enabled: true))->put(
        dimensions: ['Route' => 'api.health'],
        metrics: [],
    );
});
