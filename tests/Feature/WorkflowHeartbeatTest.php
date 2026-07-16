<?php

use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Workflow\Services\WorkflowTaskHeartbeat;
use App\Models\Copilot\OriginalDocument;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sfn\SfnClient;
use Aws\Sqs\SqsClient;
use Mockery\MockInterface;

function makeHeartbeat(MockInterface $sfn): WorkflowTaskHeartbeat
{
    return new WorkflowTaskHeartbeat($sfn, app(MetricsRecorder::class));
}

test('activate sends the initial heartbeat and throttles the following beats', function () {
    config(['mvp.workflow.heartbeat_interval_seconds' => 3600]);

    $sfn = Mockery::mock(SfnClient::class);
    $sfn->shouldReceive('sendTaskHeartbeat')
        ->twice()
        ->with(['taskToken' => 'token-1'])
        ->andReturn(new Result([]));

    $heartbeat = makeHeartbeat($sfn);
    $heartbeat->activate('token-1', 'textract.ocr');

    $heartbeat->beat();
    $heartbeat->beat();
    $heartbeat->beat(force: true);
});

test('beat sends again once the interval has elapsed', function () {
    config(['mvp.workflow.heartbeat_interval_seconds' => 1]);

    $sfn = Mockery::mock(SfnClient::class);
    $sfn->shouldReceive('sendTaskHeartbeat')->twice()->andReturn(new Result([]));

    $heartbeat = makeHeartbeat($sfn);
    $heartbeat->activate('token-1', 'bedrock.extract');

    usleep(1_100_000);
    $heartbeat->beat();
});

test('a rejected heartbeat degrades the channel without throwing', function () {
    $sfn = Mockery::mock(SfnClient::class);
    $sfn->shouldReceive('sendTaskHeartbeat')
        ->once()
        ->andThrow(new AwsException('Task timed out', new Command('sendTaskHeartbeat'), ['code' => 'TaskTimedOut']));

    $heartbeat = makeHeartbeat($sfn);
    $heartbeat->activate('token-1', 'persist.results');

    // Una volta degradato non deve piu' chiamare Step Functions.
    $heartbeat->beat(force: true);
    $heartbeat->beat(force: true);
});

test('beat is a no-op when the heartbeat is not activated', function () {
    $sfn = Mockery::mock(SfnClient::class);
    $sfn->shouldNotReceive('sendTaskHeartbeat');

    $heartbeat = makeHeartbeat($sfn);
    $heartbeat->beat();
    $heartbeat->beat(force: true);
});

test('consumer completes the task even when heartbeat and callback are rejected', function () {
    config(['services.sqs.queue_url' => 'http://localstack:4566/000000000000/mvp-documents']);

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
    ]);

    $body = json_encode([
        'taskToken' => 'token-expired',
        'taskType' => 'persist.results',
        'documentId' => $document->id,
    ], JSON_THROW_ON_ERROR);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->andReturn(new Result(['Messages' => [['Body' => $body, 'ReceiptHandle' => 'rh-1']]]));
    $sqs->shouldReceive('deleteMessage')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['ReceiptHandle'] === 'rh-1'))
        ->andReturn(new Result([]));

    $sfn = Mockery::mock(SfnClient::class);
    $sfn->shouldReceive('sendTaskHeartbeat')
        ->once()
        ->andThrow(new AwsException('Task timed out', new Command('sendTaskHeartbeat'), ['code' => 'TaskTimedOut']));
    $sfn->shouldReceive('sendTaskSuccess')
        ->once()
        ->andThrow(new AwsException('Task timed out', new Command('sendTaskSuccess'), ['code' => 'TaskTimedOut']));

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(SfnClient::class, $sfn);

    $this->artisan('mvp:workflow:consume', ['--once' => true])->assertExitCode(0);

    expect($document->workflowTasks()->where('task_type', 'persist.results')->value('status'))->toBe('succeeded');
});
