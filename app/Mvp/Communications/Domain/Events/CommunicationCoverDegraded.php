<?php

namespace App\Mvp\Communications\Domain\Events;

/**
 * Emesso sia dal passo generate_cover (fallimento del modello/storage) sia
 * da finalize (copertina rimasta pending/processing per timeout).
 */
final class CommunicationCoverDegraded
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly string $reason,
        public readonly string $warning,
    ) {}
}
