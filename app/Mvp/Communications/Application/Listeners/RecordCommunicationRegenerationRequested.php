<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationRegenerationRequested;

class RecordCommunicationRegenerationRequested
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationRegenerationRequested $event): void
    {
        $this->audit->record(
            'mvp-communication-regeneration-requested',
            $event->actor,
            'communication',
            (string) $event->communicationId,
            [],
            tenantId: $event->tenantId,
        );
    }
}
