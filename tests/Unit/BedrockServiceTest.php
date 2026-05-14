<?php

use App\Poc\Services\BedrockService;
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

    $service = new BedrockService($mockClient, 'test-model-id');
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

    $service = new BedrockService($mockClient, 'test-model-id');

    expect(fn () => $service->generateCommunication('prompt', 'formal', 'newsletter'))
        ->toThrow(RuntimeException::class);
});
