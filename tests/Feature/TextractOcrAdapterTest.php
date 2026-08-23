<?php

use App\Mvp\Documents\Adapters\Outbound\Ocr\TextractOcrAdapter;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sfn\SfnClient;
use Aws\Textract\TextractClient;

/**
 * Textract non viene mai contattato davvero: il client AWS e' un doppio e le
 * risposte sono simulate. Interessa il comportamento dell'adapter (assemblaggio
 * del testo, media di confidenza, paginazione, errori), non il servizio remoto,
 * e non la persistenza: quella e' responsabilita' del caso d'uso applicativo
 * che chiama questo adapter tramite OcrGatewayPort (vedi RunOcrUseCaseTest).
 */
function mvpMakeTextractAdapter(TextractClient $client): TextractOcrAdapter
{
    // Heartbeat mai attivato: resta no-op fuori da un task workflow.
    $heartbeat = new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class));

    return new TextractOcrAdapter($client, $heartbeat);
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

    $result = mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc');

    expect($result)->toBe([
        'enabled' => false,
        'jobId' => null,
        'text' => null,
        'pages' => [],
        'confidenceAvg' => null,
    ]);
});

test('textract requires a real bucket and key', function (string $bucket, string $key) {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldNotReceive('startDocumentTextDetection');

    expect(fn () => mvpMakeTextractAdapter($client)->detectText($bucket, $key, 'mvp-document-1-abc'))
        ->toThrow(RuntimeException::class, 'Textract richiede bucket e key S3 reali.');
})->with([
    'bucket vuoto' => ['', 'chiave.pdf'],
    'key vuota' => ['bucket', ''],
]);

test('a completed job assembles the text and averages the confidence per page', function () {
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

    $result = mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc');

    expect($result['enabled'])->toBeTrue()
        ->and($result['jobId'])->toBe('job-123')
        // I blocchi che non sono LINE restano fuori dal testo.
        ->and($result['text'])->toBe("Cedolino marzo\nMario Rossi\nCedolino aprile")
        ->and($result['confidenceAvg'])->toBe(80.0)
        // Accanto alla media resta la confidenza di ogni riga: e' cio' da cui
        // si attribuisce a ciascun campo la leggibilita' del testo che lo porta.
        ->and($result['pages'])->toBe([
            [
                'page' => 1,
                'text' => "Cedolino marzo\nMario Rossi",
                'confidenceAvg' => 85.0,
                'blocks' => [
                    ['text' => 'Cedolino marzo', 'confidence' => 90.0],
                    ['text' => 'Mario Rossi', 'confidence' => 80.0],
                ],
            ],
            [
                'page' => 2,
                'text' => 'Cedolino aprile',
                'confidenceAvg' => 70.0,
                'blocks' => [
                    ['text' => 'Cedolino aprile', 'confidence' => 70.0],
                ],
            ],
        ]);
});

test('the idempotency key is passed through as both request token and job tag', function () {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')
        ->once()
        ->withArgs(fn (array $params) => $params['ClientRequestToken'] === 'mvp-document-1-abc' && $params['JobTag'] === 'mvp-document-1-abc')
        ->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->once()
        ->andReturn(new Result(['JobStatus' => 'SUCCEEDED', 'Blocks' => []]));

    mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc');
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

    $result = mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc');

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

    $result = mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc');

    expect($result['text'])->toBe('pronto');
});

test('a job marked failed surfaces the message returned by textract', function () {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->once()
        ->andReturn(new Result(['JobStatus' => 'FAILED', 'StatusMessage' => 'Documento corrotto.']));

    expect(fn () => mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc'))
        ->toThrow(RuntimeException::class, 'Documento corrotto.');
});

test('polling gives up once the timeout has passed', function () {
    config(['services.textract.timeout_seconds' => 1]);

    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')->once()->andReturn(new Result(['JobId' => 'job-1']));
    $client->shouldReceive('getDocumentTextDetection')
        ->andReturn(new Result(['JobStatus' => 'IN_PROGRESS']));

    expect(fn () => mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc'))
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

    $result = mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc');

    // Meglio nessun valore che uno zero, che si leggerebbe come "pessima".
    expect($result['confidenceAvg'])->toBeNull()
        ->and($result['pages'][0]['confidenceAvg'])->toBeNull();
});

test('aws failures are translated into an actionable message', function (string $awsCode, string $expected) {
    $client = Mockery::mock(TextractClient::class);
    $client->shouldReceive('startDocumentTextDetection')
        ->once()
        ->andThrow(new AwsException('errore grezzo', new Command('startDocumentTextDetection'), [
            'code' => $awsCode,
            'message' => 'errore grezzo',
        ]));

    expect(fn () => mvpMakeTextractAdapter($client)->detectText('bucket', 'chiave.pdf', 'mvp-document-1-abc'))
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
