<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Commands\GenerateCommunicationCommand;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Events\CommunicationGenerationRequested;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\NewCommunication;

class GenerateCommunicationService implements GenerateCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function generate(GenerateCommunicationCommand $command): int
    {
        $communicationId = $this->communications->createCommunication(new NewCommunication(
            tenantId: $command->actor->tenantId,
            createdBy: $command->actor->id,
            prompt: $command->prompt,
            tone: $command->tone,
            style: $command->style,
            generationStatus: CommunicationGenerationStatus::Pending,
            coverStatus: CoverImageStatus::Pending,
            status: CommunicationStatus::Draft,
            isFavorite: false,
        ));

        $this->events->dispatch(new CommunicationGenerationRequested(
            $communicationId,
            $command->actor,
            $command->tone,
            $command->style,
        ));

        return $communicationId;
    }
}
