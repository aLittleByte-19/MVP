<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Support\Identity\Actor;

/**
 * Porta primaria: mutazioni dirette sul contenuto/stato di una bozza gia'
 * esistente (preferiti, testo, storico). Distinta da generazione (crea
 * l'aggregato), cancellazione (distruttiva) ed export (nessuna mutazione).
 */
interface CommunicationDraftUseCase
{
    /**
     * @throws CommunicationAlreadyFavoritedException
     */
    public function favorite(int $communicationId, Actor $actor): void;

    /**
     * @throws CommunicationNotFavoritedException
     */
    public function unfavorite(int $communicationId, Actor $actor): void;

    /**
     * @throws CommunicationNotEditableException
     */
    public function update(int $communicationId, string $title, string $body, Actor $actor): void;

    /**
     * @throws CommunicationNotDraftException
     */
    public function save(int $communicationId, Actor $actor): void;

    /**
     * @throws CommunicationAlreadyDiscardedException
     */
    public function discard(int $communicationId, Actor $actor): void;
}
