<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationDeleted;

class RecordCommunicationDeleted
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationDeleted $event): void
    {
        $this->audit->record('mvp-communication-deleted', $event->actor, 'communication', (string) $event->communicationId);
    }
}
