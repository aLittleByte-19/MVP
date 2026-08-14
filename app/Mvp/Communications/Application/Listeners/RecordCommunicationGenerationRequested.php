<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationGenerationRequested;

class RecordCommunicationGenerationRequested
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationGenerationRequested $event): void
    {
        $this->audit->record(
            'mvp-communication-generation-requested',
            $event->actor,
            'communication',
            (string) $event->communicationId,
            ['tone' => $event->tone, 'style' => $event->style],
        );
    }
}
