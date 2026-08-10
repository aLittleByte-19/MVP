<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Identity\MvpUser;

/**
 * Porta primaria: mutazioni dirette sul contenuto/stato di una bozza gia'
 * esistente (preferiti, testo, storico). Raggruppate in una porta perche'
 * sono tutte transizioni semplici sullo stesso aggregato, invocate dallo
 * stesso adapter HTTP (CommunicationController) — distinte da generazione
 * (crea l'aggregato), cancellazione (distruttiva, con cleanup storage) ed
 * export (nessuna mutazione).
 */
interface CommunicationDraftUseCase
{
    /**
     * @throws CommunicationAlreadyFavoritedException
     */
    public function favorite(int $communicationId, MvpUser $actor): void;

    /**
     * @throws CommunicationNotFavoritedException
     */
    public function unfavorite(int $communicationId, MvpUser $actor): void;

    /**
     * @throws CommunicationNotEditableException
     */
    public function update(int $communicationId, string $title, string $body, MvpUser $actor): void;

    /**
     * @throws CommunicationNotDraftException
     */
    public function save(int $communicationId, MvpUser $actor): void;

    /**
     * @throws CommunicationAlreadyDiscardedException
     */
    public function discard(int $communicationId, MvpUser $actor): void;
}
