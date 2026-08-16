<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Enums\CoverImageSource;
use App\Mvp\Communications\Domain\Events\CommunicationCoverReplaced;
use App\Mvp\Observability\MetricsRecorder;

class RecordCommunicationCoverReplaced
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(CommunicationCoverReplaced $event): void
    {
        $this->metrics->recordDomainCounter('communication_covers_generated_total', ['source' => CoverImageSource::Manual->value]);
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
