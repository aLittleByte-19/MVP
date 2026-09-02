<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowCompleted;
use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
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

        // Idempotenza verso la ridelivery del task workflow: una comunicazione
        // gia' completata non deve ri-emettere CommunicationWorkflowCompleted.
        if ($communication->generationStatus() === CommunicationGenerationStatus::Completed) {
            return ['event' => 'CommunicationPipelineCompleted', 'coverStatus' => $communication->coverStatus()->value, 'skipped' => true];
        }

        $coverStatus = $communication->coverStatus();

        // La copertina resta pending/processing solo se il task e' stato
        // saltato dal ramo di degrado dell'ASL (timeout o worker caduto): va
        // chiusa qui, altrimenti la SPA continuerebbe ad aspettarla.
        if (in_array($coverStatus, [CoverImageStatus::Pending, CoverImageStatus::Processing], true)) {
            $warning = 'Copertina AI non disponibile: generazione interrotta.';
            $communication->degradeCover($warning);
            $this->communications->saveCommunication($communication);
            $this->events->dispatch(new CommunicationCoverDegraded($communicationId, $communication->tenantId, 'timeout', $warning));
            $coverStatus = CoverImageStatus::Failed;
        }

        $communication->completeGeneration($this->clock->now());
        $this->communications->saveCommunication($communication);
        $this->events->dispatch(new CommunicationWorkflowCompleted($communicationId, $communication->tenantId));

        return ['event' => 'CommunicationPipelineCompleted', 'coverStatus' => $coverStatus->value, 'skipped' => false];
    }
}
