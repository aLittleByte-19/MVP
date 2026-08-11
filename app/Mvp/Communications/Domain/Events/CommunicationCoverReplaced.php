<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

/**
 * Emesso quando l'operatore sostituisce manualmente l'immagine di copertina
 * (distinto da CommunicationCoverGenerated, che copre la generazione AI).
 */
final class CommunicationCoverReplaced
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly MvpUser $actor,
        public readonly string $mime,
        public readonly int $size,
    ) {}
}
