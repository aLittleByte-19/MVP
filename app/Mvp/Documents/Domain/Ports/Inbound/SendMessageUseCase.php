<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\ValueObjects\RenderedSendMessage;
use App\Mvp\Identity\MvpUser;

/**
 * Porta primaria: messaggio di invio precompilato di un sotto-documento
 * (UC-48 e derivati) — anteprima, esportazione (che marca l'invio) e
 * correzione manuale dei campi.
 */
interface SendMessageUseCase
{
    public function preview(int $subDocumentId, ?MvpUser $actor): RenderedSendMessage;

    public function export(int $subDocumentId, ?MvpUser $actor): RenderedSendMessage;

    /**
     * @param  array{recipient?: ?string, subject?: ?string, body?: ?string}  $overrides  Solo le chiavi presenti vengono aggiornate.
     */
    public function updateOverrides(int $subDocumentId, array $overrides, ?MvpUser $actor): void;
}
