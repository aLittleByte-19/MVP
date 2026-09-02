<?php

namespace App\Mvp\Communications\Domain\Events;

final class CommunicationWorkflowStartFailed
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly string $message,
        public readonly string $stateMachineShortName,
    ) {}
}
