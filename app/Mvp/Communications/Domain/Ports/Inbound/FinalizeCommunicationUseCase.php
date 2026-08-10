<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

interface FinalizeCommunicationUseCase
{
    /**
     * @return array{event: string, coverStatus: string}
     */
    public function finalize(int $communicationId): array;
}
