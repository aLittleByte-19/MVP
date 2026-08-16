<?php

use App\Models\OriginalDocument;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
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

test('consumer completes the task and clears the queue message when the callback fails with a permanent error', function () {
    config(['services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents']);

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
    // TaskTimedOut e' permanente: un retry con lo stesso token non puo' mai
    // riuscire, quindi il messaggio va rimosso dalla coda anche se il
    // callback e' stato rifiutato (vedi PERMANENT_CALLBACK_ERRORS).
    $sqs->shouldReceive('deleteMessage')->once();

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

test('consumer leaves the message in queue when the callback fails with a transient error', function () {
    config(['services.workflow.task_queue_url' => 'http://localstack:4566/000000000000/mvp-documents']);

    $document = OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
    ]);

    $body = json_encode([
        'taskToken' => 'token-throttled',
        'taskType' => 'persist.results',
        'documentId' => $document->id,
    ], JSON_THROW_ON_ERROR);

    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->andReturn(new Result(['Messages' => [['Body' => $body, 'ReceiptHandle' => 'rh-1']]]));
    // ThrottlingException e' transitorio: un retry successivo con lo stesso
    // token puo' riuscire, quindi il messaggio deve restare in coda.
    $sqs->shouldNotReceive('deleteMessage');

    $sfn = Mockery::mock(SfnClient::class);
    $sfn->shouldReceive('sendTaskHeartbeat')->once()->andReturn(new Result([]));
    $sfn->shouldReceive('sendTaskSuccess')
        ->once()
        ->andThrow(new AwsException('Rate exceeded', new Command('sendTaskSuccess'), ['code' => 'ThrottlingException']));

    $this->app->instance(SqsClient::class, $sqs);
    $this->app->instance(SfnClient::class, $sfn);

    $this->artisan('mvp:workflow:consume', ['--once' => true])->assertExitCode(0);

    expect($document->workflowTasks()->where('task_type', 'persist.results')->value('status'))->toBe('succeeded');
});
