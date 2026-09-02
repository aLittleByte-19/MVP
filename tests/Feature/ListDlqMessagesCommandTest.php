<?php

use Aws\Result;
use Aws\Sqs\SqsClient;

/**
 * Il comando ispeziona la DLQ senza consumarla: e' lo strumento di diagnosi
 * quando un task fallisce ripetutamente. SQS non viene mai contattato davvero,
 * il client e' un doppio.
 */
function mvpFakeSqs(array $messages = []): SqsClient
{
    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')->andReturn(new Result(['Messages' => $messages]));

    app()->instance(SqsClient::class, $sqs);

    return $sqs;
}

beforeEach(function () {
    config([
        'services.workflow.dlq_queue_url' => 'http://localstack:4566/000000000000/mvp-documents-dlq',
        'services.workflow.communications_dlq_queue_url' => 'http://localstack:4566/000000000000/mvp-communications-dlq',
    ]);
});

test('an empty dlq is reported as empty', function () {
    mvpFakeSqs();

    $this->artisan('mvp:dlq:list')
        ->expectsOutputToContain('DLQ vuota.')
        ->assertSuccessful();
});

test('messages are listed without being deleted', function () {
    // VisibilityTimeout 0 e nessuna delete: il messaggio resta in coda e puo'
    // essere ispezionato piu' volte senza consumare i tentativi.
    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->with(Mockery::on(fn (array $params) => $params['VisibilityTimeout'] === 0
            && $params['WaitTimeSeconds'] === 0
            && str_ends_with($params['QueueUrl'], 'mvp-documents-dlq')))
        ->andReturn(new Result(['Messages' => [
            [
                'MessageId' => 'msg-1',
                'Attributes' => ['SentTimestamp' => '1700000000', 'ApproximateReceiveCount' => '3'],
                'Body' => '{"task":"document.extract_fields"}',
            ],
        ]]));
    $sqs->shouldNotReceive('deleteMessage');
    app()->instance(SqsClient::class, $sqs);

    $this->artisan('mvp:dlq:list')
        ->expectsOutputToContain('msg-1')
        ->assertSuccessful();
});

test('the communications pipeline has its own dlq', function () {
    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->with(Mockery::on(fn (array $params) => str_ends_with($params['QueueUrl'], 'mvp-communications-dlq')))
        ->andReturn(new Result(['Messages' => []]));
    app()->instance(SqsClient::class, $sqs);

    $this->artisan('mvp:dlq:list', ['--queue' => 'communications'])->assertSuccessful();
});

test('an unknown pipeline is rejected instead of falling back to a default', function () {
    // Ripiegare in silenzio sulla coda dei documenti mostrerebbe all'operatore
    // una DLQ diversa da quella che ha chiesto.
    $this->artisan('mvp:dlq:list', ['--queue' => 'inesistente'])
        ->expectsOutputToContain('Pipeline workflow non supportata')
        ->assertFailed();
});

test('an unconfigured dlq points at the command that fixes it', function () {
    config(['services.workflow.dlq_queue_url' => '']);

    $this->artisan('mvp:dlq:list')
        ->expectsOutputToContain('make refresh-runtime')
        ->assertFailed();
});

test('the number of inspected messages is capped at ten', function (int $requested, int $expected) {
    $sqs = Mockery::mock(SqsClient::class);
    $sqs->shouldReceive('receiveMessage')
        ->once()
        ->with(Mockery::on(fn (array $params) => $params['MaxNumberOfMessages'] === $expected))
        ->andReturn(new Result(['Messages' => []]));
    app()->instance(SqsClient::class, $sqs);

    $this->artisan('mvp:dlq:list', ['--limit' => $requested])->assertSuccessful();
})->with([
    'oltre il massimo consentito da SQS' => [50, 10],
    'sotto il minimo' => [0, 1],
    'valore valido' => [4, 4],
]);
