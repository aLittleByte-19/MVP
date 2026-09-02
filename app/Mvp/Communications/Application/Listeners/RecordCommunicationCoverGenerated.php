<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationCoverGenerated;

class RecordCommunicationCoverGenerated
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(CommunicationCoverGenerated $event): void
    {
        $this->audit->record(
            'mvp-communication-cover-generated',
            resourceType: 'communication',
            resourceId: (string) $event->communicationId,
            metadata: ['mime' => $event->mime],
            tenantId: $event->tenantId,
        );
    }
}
