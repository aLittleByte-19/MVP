<?php

namespace App\Mvp\Documents\Domain\Events;

final class DocumentWorkflowStarted
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId,
        public readonly string $executionArn,
        public readonly string $stateMachineArn,
        public readonly string $stateMachineShortName,
        public readonly string $taskQueueUrl,
    ) {}
}
