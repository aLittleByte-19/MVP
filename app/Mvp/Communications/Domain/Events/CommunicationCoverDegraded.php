<?php

namespace App\Mvp\Communications\Domain\Events;

/**
 * Emesso sia dal passo generate_cover (fallimento del modello/storage) sia
 * da finalize (copertina rimasta pending/processing per timeout): prima
 * dell'introduzione di questo evento la stessa coppia audit+metrica era
 * duplicata in entrambi i punti (vedi ADR 0010).
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
