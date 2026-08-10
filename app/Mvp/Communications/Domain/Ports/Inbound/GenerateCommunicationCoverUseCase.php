<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

/**
 * Porta primaria: passo async "communication.generate_cover". Un fallimento
 * degrada la copertina (stato Failed) senza far fallire il task: una
 * comunicazione valida non deve andare persa per un'immagine mancante.
 */
interface GenerateCommunicationCoverUseCase
{
    /**
     * @return array{skipped: bool, coverStatus: string}
     */
    public function generate(int $communicationId): array;
}
