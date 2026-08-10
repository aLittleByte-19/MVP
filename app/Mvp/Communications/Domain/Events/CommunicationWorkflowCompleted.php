<?php

namespace App\Mvp\Communications\Domain\Events;

final class CommunicationWorkflowCompleted
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
    ) {}
}
