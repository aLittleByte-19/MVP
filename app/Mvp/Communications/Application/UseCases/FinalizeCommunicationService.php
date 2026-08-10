<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowCompleted;
use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use Psr\Clock\ClockInterface;

class FinalizeCommunicationService implements FinalizeCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly ClockInterface $clock,
    ) {}

    public function finalize(int $communicationId): array
    {
        $communication = $this->communications->findCommunication($communicationId);
        $coverStatus = $communication->coverStatus;

        // La copertina resta pending/processing solo se il task e' stato
        // saltato dal ramo di degrado dell'ASL (timeout o worker caduto): va
        // chiusa qui, altrimenti la SPA continuerebbe ad aspettarla. Stesso
        // evento CommunicationCoverDegraded usato da
        // GenerateCommunicationCoverService::degrade() — prima la coppia
        // audit+metrica era duplicata in entrambi i punti (vedi ADR 0010).
        if (in_array($coverStatus, [CoverImageStatus::Pending->value, CoverImageStatus::Processing->value], true)) {
            $warning = 'Copertina AI non disponibile: generazione interrotta.';
            $this->communications->updateCommunication($communicationId, [
                'cover_status' => CoverImageStatus::Failed,
                'cover_error' => $warning,
            ]);
            $this->events->dispatch(new CommunicationCoverDegraded($communicationId, $communication->tenantId, 'timeout', $warning));
            $coverStatus = CoverImageStatus::Failed->value;
        }

        $this->communications->updateCommunication($communicationId, [
            'generation_status' => CommunicationGenerationStatus::Completed,
            'workflow_completed_at' => $this->clock->now(),
        ]);
        $this->events->dispatch(new CommunicationWorkflowCompleted($communicationId, $communication->tenantId));

        return ['event' => 'CommunicationPipelineCompleted', 'coverStatus' => $coverStatus];
    }
}
