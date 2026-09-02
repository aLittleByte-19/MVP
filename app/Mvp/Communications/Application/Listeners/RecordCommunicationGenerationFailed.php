<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationGenerationFailed;

class RecordCommunicationGenerationFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CommunicationGenerationFailed $event): void
    {
        $this->audit->record(
            'mvp-communication-generation-failed',
            null,
            'communication',
            (string) $event->communicationId,
            ['message' => $event->message],
            tenantId: $event->tenantId,
        );
    }
}
