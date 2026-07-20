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
