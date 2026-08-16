<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;

class RecordCommunicationDraftDiscarded
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationDraftDiscarded $event): void
    {
        $this->audit->record('mvp-communication-discarded', $event->actor, 'communication', (string) $event->communicationId);
    }
}
