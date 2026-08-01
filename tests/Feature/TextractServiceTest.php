<?php

use App\Models\OriginalDocument;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Ocr\Services\TextractService;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sfn\SfnClient;
use Aws\Textract\TextractClient;

/**
 * Textract non viene mai contattato davvero: il client AWS e' un doppio e le
 * risposte sono simulate. Interessa il comportamento del servizio (assemblaggio
 * del testo, media di confidenza, paginazione, errori), non il servizio remoto.
 */
function mvpMakeTextractService(TextractClient $client, ?MetricsRecorder $metrics = null): TextractService
{
    // Heartbeat mai attivato: resta no-op fuori da un task workflow.
    $heartbeat = new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class), app(MetricsRecorder::class));

    return new TextractService($client, $metrics ?? app(MetricsRecorder::class), $heartbeat);
}

/** Blocco LINE come lo restituisce Textract. */
function mvpLine(string $text, int $page = 1, ?float $confidence = 99.0): array
{
    $block = ['BlockType' => 'LINE', 'Text' => $text, 'Page' => $page];

    if ($confidence !== null) {
        $block['Confidence'] = $confidence;
    }

    return $block;
}

beforeEach(function () {
    config([
        'services.textract.enabled' => true,
        'services.textract.timeout_seconds' => 300,
        'services.textract.poll_interval_seconds' => 1,
    ]);
});

test('textract disabled returns an empty result without contacting aws', function () {
    config(['services.textract.enabled' => false]);

    $client = Mockery::mock(TextractClient::class);
    $client->shouldNotReceive('startDocumentTextDetection');

    $result = mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create());

    expect($result)->toBe([
        'enabled' => false,
        'job_id' => null,
        'text' => null,
        'pages' => [],
        'confidence_avg' => null,
    ]);
});

test('textract requires a real bucket and key', function (string $bucket, string $key) {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldNotReceive('startDocumentTextDetection');

    expect(fn () => mvpMakeTextractService($client)->detectText($bucket, $key, OriginalDocument::factory()->create()))
        ->toThrow(RuntimeException::class, 'Textract richiede bucket e key S3 reali.');
})->with([
    'bucket vuoto' => ['', 'chiave.pdf'],
    'key vuota' => ['bucket', ''],
]);

test('a completed job assembles the text and averages the confidence per page', function () {
    $document = OriginalDocument::factory()->create();

    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')
        ->once()
        ->andReturn(new Result(['JobId' => 'job-123']));
    $client->shouldReceive('getDocumentTextDetection')
        ->once()
        ->andReturn(new Result([
            'JobStatus' => 'SUCCEEDED',
            'Blocks' => [
                mvpLine('Cedolino marzo', 1, 90.0),
                mvpLine('Mario Rossi', 1, 80.0),
                ['BlockType' => 'WORD', 'Text' => 'ignorato', 'Page' => 1, 'Confidence' => 10.0],
                mvpLine('Cedolino aprile', 2, 70.0),
            ],
        ]));

    $result = mvpMakeTextractService($client)->detectText('bucket', 'chiave.pdf', $document);

    expect($result['enabled'])->toBeTrue()
        ->and($result['job_id'])->toBe('job-123')
        // I blocchi che non sono LINE restano fuori dal testo.
        ->and($result['text'])->toBe("Cedolino marzo\nMario Rossi\nCedolino aprile")
        ->and($result['confidence_avg'])->toBe(80.0)
        ->and($result['pages'])->toBe([
            ['page' => 1, 'text' => "Cedolino marzo\nMario Rossi", 'confidence_avg' => 85.0],
            ['page' => 2, 'text' => 'Cedolino aprile', 'confidence_avg' => 70.0],
        ]);

    $document->refresh();
    expect($document->textract_job_id)->toBe('job-123')
        ->and($document->ocr_text)->toBe("Cedolino marzo\nMario Rossi\nCedolino aprile")
        ->and((float) $document->ocr_confidence_avg)->toBe(80.0);
});

test('the idempotency token depends on the s3 object, not only on the document', function () {
    // Dopo un reset e un nuovo upload lo stesso id documento punta a un file
    // diverso: un token fisso farebbe scattare IdempotentParameterMismatch
    // contro il job precedente.
    $document = OriginalDocument::factory()->create();
    $tokens = [];

    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')
        ->twice()
        ->andReturnUsing(function (array $params) use (&$tokens) {
            $tokens[] = $params['ClientRequestToken'];

            return new Result(['JobId' => 'job-'.count($tokens)]);
        });
    $client->shouldReceive('getDocumentTextDetection')
        ->twice()
        ->andReturn(new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => []]));

    $service = mvpMakeTextractService($client);
    $service->detectText('bucket', 'primo.pdf', $document);
    $service->detectText('bucket', 'secondo.pdf', $document);

    expect($tokens[0])->not->toBe($tokens[1])
        ->and($tokens[0])->toStartWith('mvp-document-'.$document->id.'-');
});

test('paginated results are collected across pages', function () {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->twice()
        ->andReturn(
            new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => [mvpLine('prima parte')], 'NextToken' => 'token-2']),
            new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => [mvpLine('seconda parte')]]),
        );

    $result = mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create());

    expect($result['text'])->toBe("prima parte\nseconda parte");
});

test('a job still running is polled again until it succeeds', function () {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->twice()
        ->andReturn(
            new Result(['JobStatus' => 'IN_PROGRESS']),
            new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => [mvpLine('pronto')]]),
        );

    $result = mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create());

    expect($result['text'])->toBe('pronto');
});

test('a job marked failed surfaces the message returned by textract', function () {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->once()
        ->andReturn(new Result(['JobStatus' => 'FAILED', 'StatusMessage' => 'Documento corrotto.']));

    expect(fn () => mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create()))
        ->toThrow(RuntimeException::class, 'Documento corrotto.');
});

test('polling gives up once the timeout has passed', function () {
    config(['services.textract.timeout_seconds' => 1]);

    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->andReturn(new Result(['JobStatus' => 'IN_PROGRESS']));

    expect(fn () => mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create()))
        ->toThrow(RuntimeException::class, 'Timeout Textract dopo 1 secondi.');
});

test('lines without a confidence value leave the average undefined', function () {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->once()
        ->andReturn(new Result([
            'JobStatus' => 'SUCCEEDED',
            'Blocks' => [mvpLine('senza confidenza', 1, null)],
        ]));

    $result = mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create());

    // Meglio nessun valore che uno zero, che si leggerebbe come "pessima".
    expect($result['confidence_avg'])->toBeNull()
        ->and($result['pages'][0]['confidence_avg'])->toBeNull();
});

test('aws failures are translated into an actionable message', function (string $awsCode, string $expected) {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')
        ->once()
        ->andThrow(new AwsException('errore grezzo', new Command('startDocumentTextDetection'), [
            'code' => $awsCode,
            'message' => 'errore grezzo',
        ]));

    expect(fn () => mvpMakeTextractService($client)
        ->detectText('bucket', 'chiave.pdf', OriginalDocument::factory()->create()))
        ->toThrow(RuntimeException::class, $expected);
})->with([
    ['AccessDeniedException', 'Textract non autorizzato: verifica IAM e bucket S3 reale.'],
    ['AccessDenied', 'Textract non autorizzato: verifica IAM e bucket S3 reale.'],
    ['InvalidS3ObjectException', 'Textract non riesce a leggere il documento da S3 reale.'],
    ['ThrottlingException', 'Textract è temporaneamente limitato per throttling.'],
    ['ProvisionedThroughputExceededException', 'Textract è temporaneamente limitato per throttling.'],
    ['DocumentTooLargeException', 'Documento troppo grande per Textract.'],
    ['UnsupportedDocumentException', 'Formato documento non supportato da Textract.'],
    ['QualcosAltro', 'Errore Textract:'],
]);
