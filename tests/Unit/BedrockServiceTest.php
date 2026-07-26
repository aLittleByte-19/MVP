<?php

use App\Copilot\Ai\AiOutputValidator;
use App\Copilot\Ai\BedrockService;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;

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

    $service = new BedrockService($mockClient, 'test-model-id', null, new AiOutputValidator);
    $result = $service->generateCommunication('Scrivi una comunicazione', 'formal', 'newsletter');

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

    $service = new BedrockService($mockClient, 'test-model-id', null, new AiOutputValidator);

    expect(fn () => $service->generateCommunication('prompt', 'formal', 'newsletter'))
        ->toThrow(RuntimeException::class);
});

test('generateCommunicationImage returns data url from nova response', function () {
    $base64 = base64_encode('fake-image');
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->with(Mockery::on(function (array $payload): bool {
            return ($payload['modelId'] ?? null) === 'nova-canvas';
        }))
        ->andReturnUsing(function (array $payload) use ($base64) {
            $body = json_decode((string) ($payload['body'] ?? '{}'), true);

            expect($body)->toBeArray();
            expect($body['imageGenerationConfig']['width'] ?? null)->toBe(1280);
            expect($body['imageGenerationConfig']['height'] ?? null)->toBe(720);

            return new Result([
                'body' => json_encode([
                    'images' => [$base64],
                ]),
            ]);
        });

    $service = new BedrockService($mockClient, 'text-model', 'nova-canvas', new AiOutputValidator);
    $result = $service->generateCommunicationImage('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result)->toBe("data:image/png;base64,{$base64}");
});

test('generateCommunicationImage returns null when image model id is missing', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);
    $mockClient->shouldNotReceive('invokeModel');

    $service = new BedrockService($mockClient, 'text-model', null, new AiOutputValidator);
    $result = $service->generateCommunicationImage('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result)->toBeNull();
});

test('generateCommunicationImage retries with alternative supported size', function () {
    $base64 = base64_encode('retry-image');
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $firstException = new AwsException('ValidationException: unsupported size', new Command('invokeModel'));

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andThrow($firstException);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'images' => [$base64],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'nova-canvas', new AiOutputValidator);
    $result = $service->generateCommunicationImage('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result)->toBe("data:image/png;base64,{$base64}");
});

test('generateCommunicationImage uses stability diffusion payload when model is stability', function () {
    $base64 = base64_encode('stability-image');
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
                'artifacts' => [['base64' => $base64, 'mimeType' => 'image/png']],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'stability.stable-diffusion-xl-v1', new AiOutputValidator);
    $result = $service->generateCommunicationImage('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result)->toBe("data:image/png;base64,{$base64}");
});

test('generateCommunicationImage uses stability core payload when model is stable-image', function () {
    $base64 = base64_encode('stability-core-image');
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
        ->andReturn(new Result([
            'body' => json_encode([
                'images' => [$base64],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'stability.stable-image-core-v1:0', new AiOutputValidator);
    $result = $service->generateCommunicationImage('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result)->toBe("data:image/png;base64,{$base64}");
});

test('generateCommunicationImage uses stability core payload when model is sd3-5-large', function () {
    $base64 = base64_encode('stability-sd35-image');
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
                && isset($body['prompt'])
                && isset($body['negative_prompt']);
        }))
        ->andReturn(new Result([
            'body' => json_encode([
                'images' => [$base64],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0', new AiOutputValidator);
    $result = $service->generateCommunicationImage('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result)->toBe("data:image/png;base64,{$base64}");
});

test('generateCommunicationImageWithMeta returns safety warning when stability filters content', function () {
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'finish_reasons' => ['CONTENT_FILTERED'],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0', new AiOutputValidator);
    $result = $service->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['image'])->toBeNull()
        ->and($result['warning'])->toContain('controlli di sicurezza');
});

test('generateCommunicationImage retries with sanitized prompt after prompt filter reason', function () {
    $base64 = base64_encode('stability-sanitized-fallback-image');
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'finish_reasons' => ['Filter reason: prompt'],
            ]),
        ]));

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'images' => [$base64],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0', new AiOutputValidator);
    $result = $service->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['image'])->toBe("data:image/png;base64,{$base64}")
        ->and($result['warning'])->toBeNull();
});

test('generateCommunicationImage keeps retrying after prompt filter while using sanitized prompt', function () {
    $base64 = base64_encode('stability-sanitized-third-attempt-image');
    $mockClient = Mockery::mock(BedrockRuntimeClient::class);

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'finish_reasons' => ['Filter reason: prompt'],
            ]),
        ]));

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'finish_reasons' => ['Filter reason: prompt'],
            ]),
        ]));

    $mockClient->shouldReceive('invokeModel')
        ->once()
        ->andReturn(new Result([
            'body' => json_encode([
                'images' => [$base64],
            ]),
        ]));

    $service = new BedrockService($mockClient, 'text-model', 'stability.sd3-5-large-v1:0', new AiOutputValidator);
    $result = $service->generateCommunicationImageWithMeta('Prompt di test', 'Chiaro e diretto', 'Testo informativo');

    expect($result['image'])->toBe("data:image/png;base64,{$base64}")
        ->and($result['warning'])->toBeNull();
});
