<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationTextGenerated;

class RecordCommunicationTextGenerated
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationTextGenerated $event): void
    {
        $this->audit->record(
            'mvp-communication-generated',
            resourceType: 'communication',
            resourceId: (string) $event->communicationId,
            metadata: ['tone' => $event->tone, 'style' => $event->style],
            tenantId: $event->tenantId,
        );
    }
}
