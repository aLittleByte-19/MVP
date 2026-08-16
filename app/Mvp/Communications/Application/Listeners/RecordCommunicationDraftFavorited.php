<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;

class RecordCommunicationDraftFavorited
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationDraftFavorited $event): void
    {
        $this->audit->record('mvp-communication-favorited', $event->actor, 'communication', (string) $event->communicationId);
    }
}
