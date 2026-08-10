<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationDraftUnfavorited;

class RecordCommunicationDraftUnfavorited
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationDraftUnfavorited $event): void
    {
        $this->audit->record('mvp-communication-unfavorited', $event->actor, 'communication', (string) $event->communicationId);
    }
}
