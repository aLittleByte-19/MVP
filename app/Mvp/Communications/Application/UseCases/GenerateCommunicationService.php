<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Commands\GenerateCommunicationCommand;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\NewCommunication;

class GenerateCommunicationService implements GenerateCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly AuditLogger $audit,
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

        $this->audit->record(
            'mvp-communication-generation-requested',
            $command->actor,
            'communication',
            (string) $communicationId,
            ['tone' => $command->tone, 'style' => $command->style],
        );

        return $communicationId;
    }
}
