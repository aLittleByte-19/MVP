<?php

namespace App\Mvp\Communications\Domain\Events;

final class CommunicationWorkflowStarted
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly string $executionArn,
        public readonly string $stateMachineArn,
        public readonly string $stateMachineShortName,
        public readonly string $taskQueueUrl,
    ) {}
}
