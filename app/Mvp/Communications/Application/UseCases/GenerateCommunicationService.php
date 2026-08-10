<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Commands\GenerateCommunicationCommand;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;

class GenerateCommunicationService implements GenerateCommunicationUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly AuditLogger $audit,
    ) {}

    public function generate(GenerateCommunicationCommand $command): int
    {
        $communicationId = $this->communications->createCommunication([
            'tenant_id' => $command->actor->tenantId,
            'created_by' => $command->actor->id,
            'prompt' => $command->prompt,
            'tone' => $command->tone,
            'style' => $command->style,
            'generation_status' => CommunicationGenerationStatus::Pending,
            'cover_status' => CoverImageStatus::Pending,
            'status' => CommunicationStatus::Draft,
            'is_favorite' => false,
        ]);

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
