<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowCompleted;
use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
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

        // Idempotenza verso la ridelivery del task workflow: una comunicazione
        // gia' completata non deve ri-emettere CommunicationWorkflowCompleted
        // (vedi CommunicationWorkflowTaskHandler e il trattamento analogo su
        // ProcessDocumentService).
        if ($communication->generationStatus === CommunicationGenerationStatus::Completed->value) {
            return ['event' => 'CommunicationPipelineCompleted', 'coverStatus' => $communication->coverStatus, 'skipped' => true];
        }

        $coverStatus = $communication->coverStatus;

        // La copertina resta pending/processing solo se il task e' stato
        // saltato dal ramo di degrado dell'ASL (timeout o worker caduto): va
        // chiusa qui, altrimenti la SPA continuerebbe ad aspettarla. Stesso
        // evento CommunicationCoverDegraded usato da
        // GenerateCommunicationCoverService::degrade() — prima la coppia
        // audit+metrica era duplicata in entrambi i punti (vedi ADR 0010).
        if (in_array($coverStatus, [CoverImageStatus::Pending->value, CoverImageStatus::Processing->value], true)) {
            $warning = 'Copertina AI non disponibile: generazione interrotta.';
            $this->communications->updateCommunication($communicationId, CommunicationChanges::none()
                ->withCoverStatus(CoverImageStatus::Failed)
                ->withCoverError($warning));
            $this->events->dispatch(new CommunicationCoverDegraded($communicationId, $communication->tenantId, 'timeout', $warning));
            $coverStatus = CoverImageStatus::Failed->value;
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()
            ->withGenerationStatus(CommunicationGenerationStatus::Completed)
            ->withWorkflowCompletedAt($this->clock->now()));
        $this->events->dispatch(new CommunicationWorkflowCompleted($communicationId, $communication->tenantId));

        return ['event' => 'CommunicationPipelineCompleted', 'coverStatus' => $coverStatus, 'skipped' => false];
    }
}
