<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationCoverRemoved;

class RecordCommunicationCoverRemoved
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationCoverRemoved $event): void
    {
        $this->audit->record(
            'mvp-communication-cover-removed',
            $event->actor,
            'communication',
            (string) $event->communicationId,
            [],
            tenantId: $event->tenantId,
        );
    }
}
