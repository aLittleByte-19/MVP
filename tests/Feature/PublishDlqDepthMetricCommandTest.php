<?php

use App\Mvp\Observability\EmfMetricsRecorder;
use Aws\Result;
use Aws\Sqs\SqsClient;

beforeEach(function () {
    config([
        'services.workflow.dlq_queue_url' => 'http://localstack:4566/000000000000/mvp-documents-dlq',
        'services.workflow.communications_dlq_queue_url' => 'http://localstack:4566/000000000000/mvp-communications-dlq',
    ]);
});

test('the dlq depth of both pipelines is published as a metric', function () {
    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('getQueueAttributes')
        ->once()
        ->with(Mockery::on(fn (array $params) => str_ends_with($params['QueueUrl'], 'mvp-documents-dlq')))
        ->andReturn(new Result(['Attributes' => ['ApproximateNumberOfMessages' => '3']]));
    $sqs->shouldReceive('getQueueAttributes')
        ->once()
        ->with(Mockery::on(fn (array $params) => str_ends_with($params['QueueUrl'], 'mvp-communications-dlq')))
        ->andReturn(new Result(['Attributes' => ['ApproximateNumberOfMessages' => '0']]));
    app()->instance(SqsClient::class, $sqs);

    $metrics = Mockery::mock(EmfMetricsRecorder::class);
    $metrics->shouldReceive('put')
        ->once()
        ->with(['Pipeline' => 'documents'], ['DlqDepth' => ['value' => 3, 'unit' => 'Count']]);
    $metrics->shouldReceive('put')
        ->once()
        ->with(['Pipeline' => 'communications'], ['DlqDepth' => ['value' => 0, 'unit' => 'Count']]);
    app()->instance(EmfMetricsRecorder::class, $metrics);

    $this->artisan('mvp:metrics:dlq-depth')->assertSuccessful();
});

test('a pipeline without a configured dlq is skipped instead of failing', function () {
    config(['services.workflow.communications_dlq_queue_url' => '']);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('getQueueAttributes')
        ->once()
        ->andReturn(new Result(['Attributes' => ['ApproximateNumberOfMessages' => '1']]));
    app()->instance(SqsClient::class, $sqs);

    $metrics = Mockery::mock(EmfMetricsRecorder::class);
    $metrics->shouldReceive('put')->once()->with(['Pipeline' => 'documents'], Mockery::any());
    app()->instance(EmfMetricsRecorder::class, $metrics);

    $this->artisan('mvp:metrics:dlq-depth')->assertSuccessful();
});
