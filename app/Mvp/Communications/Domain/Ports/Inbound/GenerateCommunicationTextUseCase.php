<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

/**
 * Porta primaria: passo async "communication.generate_text". Adapter
 * primario: CommunicationWorkflowTaskHandler, guidato da Step Functions.
 */
interface GenerateCommunicationTextUseCase
{
    /**
     * @return array{skipped: bool, title: ?string}
     */
    public function generate(int $communicationId): array;
}
