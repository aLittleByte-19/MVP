<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Support\Identity\Actor;

interface StartCommunicationWorkflowUseCase
{
    public function start(int $communicationId, ?string $correlationId, ?string $requestId): void;

    /**
     * Azzera testo e copertina generati e rilancia la pipeline sulla stessa riga.
     */
    public function regenerate(int $communicationId, ?Actor $actor, ?string $correlationId, ?string $requestId): void;
}
