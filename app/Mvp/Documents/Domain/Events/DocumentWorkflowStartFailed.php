<?php

namespace App\Mvp\Documents\Domain\Events;

final class DocumentWorkflowStartFailed
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId,
        public readonly string $message,
        public readonly string $stateMachineShortName,
    ) {}
}
