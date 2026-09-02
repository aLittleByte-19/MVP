<?php

namespace App\Mvp\Workflow\Adapters\Outbound;

use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;

/**
 * Adapter secondario: implementa {@see WorkflowEnginePort} sopra
 * AWS Step Functions. Condiviso fra Documents e Communications.
 */
class SfnWorkflowEngineAdapter implements WorkflowEnginePort
{
    public function __construct(private readonly SfnClient $client) {}

    public function startExecution(string $stateMachineArn, string $executionName, array $input): string
    {
        try {
            $result = $this->client->startExecution([
                'stateMachineArn' => $stateMachineArn,
                'name' => $executionName,
                'input' => json_encode($input, JSON_THROW_ON_ERROR),
            ]);

            return (string) $result->get('executionArn');
        } catch (AwsException $e) {
            throw new \RuntimeException('Impossibile avviare la pipeline Step Functions: '.$e->getAwsErrorMessage(), previous: $e);
        }
    }
}
