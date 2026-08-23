<?php

use App\Mvp\Ai\AiOutputValidator;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Sfn\SfnClient;

function makeBedrockService(BedrockRuntimeClient $client, ?string $modelId = 'test-model-id', ?string $imageModelId = null): BedrockService
{
    // Heartbeat mai attivato: resta no-op fuori da un task workflow.
    $heartbeat = new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class));

    // Stesso doppio per testo e immagini: la separazione dei client riguarda la
    // region, non il comportamento verificato qui.
    return new BedrockService($client, $client, $modelId, $imageModelId, new AiOutputValidator, $heartbeat);
}

test('generateCommunication returns title and body on success', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);
    $mockClient->shouldReceive('converse')
        ->once()
        ->andReturn(new Result([
            'output' => [
                'message' => [
                    'content' => [
                        ['text' => json_encode(['title' => 'Titolo test', 'body' => 'Corpo del testo generato'])],
                    ],
                ],
            ],
        ]));

    $result = makeBedrockService($mockClient)->generateCommunication('Scrivi una comunicazione', 'formal', 'newsletter');

    expect($result)->toBeArray()
        ->toHaveKeys(['title', 'body'])
        ->and($result['title'])->toBe('Titolo test')
        ->and($result['body'])->toBe('Corpo del testo generato');
});

test('generateCommunication throws RuntimeException on Bedrock failure', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);
    $mockClient->shouldReceive('converse')
        ->once()
        ->andThrow(new AwsException('Service error', new Command('converse')));

    $service = makeBedrockService($mockClient);

    expect(fn () => $service->generateCommunication('prompt', 'formal', 'newsletter'))
        ->toThrow(RuntimeException::class);
});

test('cover generation returns decoded bytes from a nova response', function () {
    $bytes = 'fake-image';
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(fn (array $payload): bool => ($payload['modelId'] ?? null) === 'nova-canvas'))
        ->andReturnUsing(function (array $payload) use ($bytes) {
            $body = json_decode((string) ($payload['body'] ?? '{}'), true);

            expect($body)->toBeArray();
            expect($body['imageGenerationConfig']['width'] ?? null)->toBe(1280);
            expect($body['imageGenerationConfig']['height'] ?? null)->toBe(720);

            return new Result(['body' => json_encode(['images' => [base64_encode($bytes)]])]);
        });

    $result = makeBedrockService($mockClient, 'text-model', 'nova-canvas')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBe($bytes)
        ->and($result['mime'])->toBe('image/png')
        ->and($result['warning'])->toBeNull();
});

test('cover generation uses the visual direction written by the text model', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            $body = json_decode((string) ($payload['body'] ?? '{}'), true);
            $text = $body['textToImageParams']['text'] ?? '';

            // Il soggetto e' quello del modello, non il prompt grezzo dell'operatore.
            return str_contains($text, 'Confetti and balloons over a warm gradient')
                && ! str_contains($text, 'Auguri di buon compleanno a Mario');
        }))
        ->andReturn(new Result(['body' => json_encode(['images' => [base64_encode('img')]])]));

    $result = makeBedrockService($mockClient, 'text-model', 'nova-canvas')
        ->generateCommunicationImageWithMeta(
            'Auguri di buon compleanno a Mario',
            'Empatico',
            'Aggiornamento breve',
            'Confetti and balloons over a warm gradient, festive corporate mood',
        );

    expect($result['bytes'])->toBe('img');
});

test('cover generation falls back to a generic subject without a model prompt', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            $body = json_decode((string) ($payload['body'] ?? '{}'), true);

            return str_contains($body['textToImageParams']['text'] ?? '', 'Internal company communication about:');
        }))
        ->andReturn(new Result(['body' => json_encode(['images' => [base64_encode('img')]])]));

    $result = makeBedrockService($mockClient, 'text-model', 'nova-canvas')
        ->generateCommunicationImageWithMeta('Chiusura uffici', 'Chiaro e diretto', 'Avviso operativo', null);

    expect($result['bytes'])->toBe('img');
});

test('cover generation reports a warning when no image model is configured', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);
    $mockClient->shouldNotReceive('invokeModel');

    $result = makeBedrockService($mockClient, 'text-model', null)
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBeNull()
        ->and($result['reason'])->toBe('model_not_configured')
        ->and($result['warning'])->toContain('non configurato');
});

test('cover generation retries with an alternative supported size', function () {
    $bytes = 'retry-image';
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andThrow(new AwsException('ValidationException: unsupported size', new Command('invokeModel')));

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result(['body' => json_encode(['images' => [base64_encode($bytes)]])]));

    $result = makeBedrockService($mockClient, 'text-model', 'nova-canvas')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBe($bytes);
});

test('cover generation stops at the first attempt when the model is not accessible', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    // Una sola chiamata: l'accesso negato non cambia al variare della size.
    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andThrow(new AwsException('Model access is denied for this account', new Command('invokeModel')));

    $result = makeBedrockService($mockClient, 'text-model', 'nova-canvas')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBeNull()
        ->and($result['reason'])->toBe('model_access_denied');
});

test('cover generation uses the stability diffusion payload when the model is stability', function () {
    $bytes = 'stability-image';
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            if (($payload['modelId'] ?? null) !== 'stability.stable-diffusion-xl-v1') {
                return false;
            }

            $body = json_decode((string) ($payload['body'] ?? '{}'), true);
            if (! is_array($body)) {
                return false;
            }

            return isset($body['text_prompts'])
                && is_array($body['text_prompts'])
                && ($body['text_prompts'][1]['weight'] ?? null) === -1
                && ($body['width'] ?? null) === 1280
                && ($body['height'] ?? null) === 720;
        }))
        ->andReturn(new Result([
            'body' => json_encode([
                'artifacts' => [['base64' => base64_encode($bytes), 'mimeType' => 'image/png']],
            ]),
        ]));

    $result = makeBedrockService($mockClient, 'text-model', 'stability.stable-diffusion-xl-v1')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBe($bytes);
});

test('cover generation uses the stability core payload when the model is stable-image', function () {
    $bytes = 'stability-core-image';
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            if (($payload['modelId'] ?? null) !== 'stability.stable-image-core-v1:0') {
                return false;
            }

            $body = json_decode((string) ($payload['body'] ?? '{}'), true);
            if (! is_array($body)) {
                return false;
            }

            return ($body['output_format'] ?? null) === 'png'
                && ($body['aspect_ratio'] ?? null) === '16:9'
                && isset($body['prompt'])
                && isset($body['negative_prompt']);
        }))
        ->andReturn(new Result(['body' => json_encode(['images' => [base64_encode($bytes)]])]));

    $result = makeBedrockService($mockClient, 'text-model', 'stability.stable-image-core-v1:0')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBe($bytes);
});

test('cover generation uses the stability core payload when the model is sd3-5-large', function () {
    $bytes = 'stability-sd35-image';
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            if (($payload['modelId'] ?? null) !== 'stability.sd3-5-large-v1:0') {
                return false;
            }

            $body = json_decode((string) ($payload['body'] ?? '{}'), true);
            if (! is_array($body)) {
                return false;
            }

            return ($body['output_format'] ?? null) === 'png'
                && ($body['aspect_ratio'] ?? null) === '16:9'
                && ($body['mode'] ?? null) === 'text-to-image'
                && isset($body['prompt']);
        }))
        ->andReturn(new Result(['body' => json_encode(['images' => [base64_encode($bytes)]])]));

    $result = makeBedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBe($bytes);
});

test('cover generation reports a safety warning when the model filters the content', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result(['body' => json_encode(['finish_reasons' => ['CONTENT_FILTERED']])]));

    $result = makeBedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBeNull()
        ->and($result['reason'])->toBe('content_filter')
        ->and($result['warning'])->toContain('controlli di sicurezza');
});

test('cover generation retries with a sanitized prompt after a prompt filter reason', function () {
    $bytes = 'stability-sanitized-fallback-image';
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result(['body' => json_encode(['finish_reasons' => ['Filter reason: prompt']])]));

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result(['body' => json_encode(['images' => [base64_encode($bytes)]])]));

    $result = makeBedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBe($bytes)
        ->and($result['warning'])->toBeNull();
});

test('cover generation gives up when the sanitized prompt is filtered too', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->twice()
        ->andReturn(new Result(['body' => json_encode(['finish_reasons' => ['Filter reason: prompt']])]));

    $result = makeBedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0')
        ->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['bytes'])->toBeNull()
        ->and($result['reason'])->toBe('content_filter');
});
