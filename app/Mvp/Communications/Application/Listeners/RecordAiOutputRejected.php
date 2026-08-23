<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\AiOutputRejected;

class RecordAiOutputRejected
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(AiOutputRejected $event): void
    {
        $this->audit->record(
            'mvp-ai-output-invalid',
            resourceType: 'communication',
            resourceId: (string) $event->communicationId,
            metadata: ['operation' => $event->operation, 'errors' => $event->errors],
            tenantId: $event->tenantId,
        );
    }
}
