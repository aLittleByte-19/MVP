<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

/**
 * Emesso quando l'operatore rimuove manualmente l'immagine di copertina.
 */
final class CommunicationCoverRemoved
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly MvpUser $actor,
    ) {}
}
