<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Ports\Inbound\DeleteCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Identity\MvpUser;

class DeleteCommunicationService implements DeleteCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $coverStorage,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $this->communications->deleteCommunication($communicationId);

        if ($communication->coverImagePath !== null) {
            $this->coverStorage->delete($communication->coverImagePath);
        }

        $this->audit->record('mvp-communication-deleted', $actor, 'communication', (string) $communicationId);
    }
}
