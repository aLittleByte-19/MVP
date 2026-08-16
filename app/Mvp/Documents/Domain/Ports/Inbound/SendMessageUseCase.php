<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\ValueObjects\RenderedSendMessage;
use App\Mvp\Support\Identity\Actor;

/**
 * Porta primaria: messaggio di invio precompilato di un sotto-documento
 * (UC-48 e derivati) — anteprima, esportazione (che marca l'invio) e
 * correzione manuale dei campi.
 */
interface SendMessageUseCase
{
    /**
     * @throws DocumentNotAuthorizedException
     */
    public function preview(int $subDocumentId, Actor $actor): RenderedSendMessage;

    /**
     * @throws DocumentNotAuthorizedException
     */
    public function export(int $subDocumentId, Actor $actor): RenderedSendMessage;

    /**
     * @param  array{recipient?: ?string, subject?: ?string, body?: ?string}  $overrides  Solo le chiavi presenti vengono aggiornate.
     *
     * @throws DocumentNotAuthorizedException
     */
    public function updateOverrides(int $subDocumentId, array $overrides, Actor $actor): void;
}
