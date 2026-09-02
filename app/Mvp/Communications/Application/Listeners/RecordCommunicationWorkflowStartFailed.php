<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;

class RecordCommunicationWorkflowStartFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CommunicationWorkflowStartFailed $event): void
    {
        $this->audit->record(
            'mvp-communication-workflow-start-failed',
            null,
            'communication',
            (string) $event->communicationId,
            ['message' => $event->message],
            tenantId: $event->tenantId,
        );
    }
}
