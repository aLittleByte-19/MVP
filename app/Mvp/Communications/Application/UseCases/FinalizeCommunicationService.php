<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Observability\MetricsRecorder;

class FinalizeCommunicationService implements FinalizeCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function finalize(int $communicationId): array
    {
        $communication = $this->communications->findCommunication($communicationId);
        $coverStatus = $communication->coverStatus;

        // La copertina resta pending solo se il task e' stato saltato dal ramo
        // di degrado dell'ASL (timeout o worker caduto): va chiusa qui,
        // altrimenti la SPA continuerebbe ad aspettarla.
        if (in_array($coverStatus, [CoverImageStatus::Pending->value, CoverImageStatus::Processing->value], true)) {
            $this->communications->updateCommunication($communicationId, [
                'cover_status' => CoverImageStatus::Failed,
                'cover_error' => 'Copertina AI non disponibile: generazione interrotta.',
            ]);
            $this->metrics->recordDomainCounter('communication_covers_failed_total', ['reason' => 'timeout']);
            $this->audit->record(
                'mvp-communication-cover-degraded',
                resourceType: 'communication',
                resourceId: (string) $communicationId,
                metadata: ['reason' => 'timeout'],
                tenantId: $communication->tenantId,
            );
            $coverStatus = CoverImageStatus::Failed->value;
        }

        $this->communications->updateCommunication($communicationId, [
            'generation_status' => CommunicationGenerationStatus::Completed,
            'workflow_completed_at' => now(),
        ]);
        $this->metrics->recordDomainCounter('communication_workflow_completed_total');

        return ['event' => 'CommunicationPipelineCompleted', 'coverStatus' => $coverStatus];
    }
}
