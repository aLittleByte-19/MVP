<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationCoverReplaced;

class RecordCommunicationCoverReplaced
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CommunicationCoverReplaced $event): void
    {
        $this->audit->record(
            'mvp-communication-cover-updated',
            $event->actor,
            'communication',
            (string) $event->communicationId,
            ['mime' => $event->mime, 'size' => $event->size],
            tenantId: $event->tenantId,
        );
    }
}
