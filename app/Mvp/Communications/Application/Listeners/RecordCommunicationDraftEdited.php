<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationDraftEdited;

class RecordCommunicationDraftEdited
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationDraftEdited $event): void
    {
        $this->audit->record('mvp-communication-edited', $event->actor, 'communication', (string) $event->communicationId, ['fields' => ['title', 'body']]);
    }
}
