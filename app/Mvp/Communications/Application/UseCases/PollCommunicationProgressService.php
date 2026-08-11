<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Ports\Inbound\PollCommunicationProgressUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationProgressSnapshot;

class PollCommunicationProgressService implements PollCommunicationProgressUseCase
{
    public function __construct(private readonly CommunicationRepository $communications) {}

    public function poll(int $communicationId): CommunicationProgressSnapshot
    {
        $communication = $this->communications->findCommunication($communicationId);

        return new CommunicationProgressSnapshot(
            $communication->generationStatus,
            $communication->coverStatus,
            $communication->generatedTitle,
            $communication->generatedBody,
            $communication->coverError,
            $communication->errorMessage,
        );
    }
}
