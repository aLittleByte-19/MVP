<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationDeleted;
use App\Mvp\Communications\Domain\Ports\Inbound\DeleteCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Identity\MvpUser;

class DeleteCommunicationService implements DeleteCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $coverStorage,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function delete(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $this->communications->deleteCommunication($communicationId);

        if ($communication->coverImagePath !== null) {
            $this->coverStorage->delete($communication->coverImagePath);
        }

        $this->events->dispatch(new CommunicationDeleted($communicationId, $actor));
    }
}
