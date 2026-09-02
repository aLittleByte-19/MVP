<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;

class RecordCommunicationDraftApproved
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationDraftApproved $event): void
    {
        $this->audit->record('mvp-communication-saved', $event->actor, 'communication', (string) $event->communicationId);
    }
}
