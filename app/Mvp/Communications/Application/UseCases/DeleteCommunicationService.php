<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationDeleted;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotAuthorizedException;
use App\Mvp\Communications\Domain\Ports\Inbound\DeleteCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identity\Actor;
use Psr\Log\LoggerInterface;

class DeleteCommunicationService implements DeleteCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $coverStorage,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function delete(int $communicationId, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        // Difesa in profondita': stesso controllo gia' fatto a livello HTTP
        // da AuthorizesCommunications (vedi il docblock di CommunicationDraftService).
        if ($communication->tenantId !== $actor->tenantId) {
            throw new CommunicationNotAuthorizedException;
        }

        $this->communications->deleteCommunication($communicationId);

        $coverImagePath = $communication->coverImagePath();

        if ($coverImagePath !== null) {
            // Il record e' gia' rimosso dal DB a questo punto: un guasto di
            // storage non deve far fallire un'eliminazione che dal punto di
            // vista dell'utente e' gia' avvenuta — resta solo un file orfano,
            // ripulibile in un secondo momento, non un riferimento pendente
            // (vedi ADR 0010, stesso trattamento di DeleteDocumentService).
            try {
                $this->coverStorage->delete($coverImagePath);
            } catch (\Throwable $e) {
                $this->logger->warning('DeleteCommunicationService: storage cleanup failed', ['path' => $coverImagePath, 'message' => $e->getMessage()]);
            }
        }

        $this->events->dispatch(new CommunicationDeleted($communicationId, $actor));
    }
}
