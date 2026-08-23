<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;

class RecordCommunicationCoverDegraded
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CommunicationCoverDegraded $event): void
    {
        $this->audit->record(
            'mvp-communication-cover-degraded',
            resourceType: 'communication',
            resourceId: (string) $event->communicationId,
            metadata: ['reason' => $event->reason],
            tenantId: $event->tenantId,
        );
    }
}
