<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;

final class FakeWorkflowEngine implements WorkflowEnginePort
{
    private ?\Throwable $failure = null;

    private string $executionArn = 'arn:aws:states:eu-north-1:000000000000:execution:fake:test';

    /** @var array{stateMachineArn: string, executionName: string, input: array<string, mixed>}|null */
    private ?array $lastCall = null;

    public function willFailWith(\Throwable $exception): void
    {
        $this->failure = $exception;
    }

    public function willReturnExecutionArn(string $arn): void
    {
        $this->executionArn = $arn;
    }

    public function startExecution(string $stateMachineArn, string $executionName, array $input): string
    {
        $this->lastCall = ['stateMachineArn' => $stateMachineArn, 'executionName' => $executionName, 'input' => $input];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->executionArn;
    }

    /** @return array{stateMachineArn: string, executionName: string, input: array<string, mixed>}|null */
    public function lastCall(): ?array
    {
        return $this->lastCall;
    }
}
