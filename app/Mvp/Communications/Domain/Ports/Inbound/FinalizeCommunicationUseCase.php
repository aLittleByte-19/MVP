<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

interface FinalizeCommunicationUseCase
{
    /**
     * @return array{event: string, coverStatus: string, skipped: bool}
     */
    public function finalize(int $communicationId): array;
}
