<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationCoverUnavailableException;
use App\Mvp\Communications\Domain\ValueObjects\DownloadableCoverImage;

/**
 * Porta primaria: download/anteprima dell'immagine di copertina di una comunicazione.
 */
interface DownloadCommunicationCoverUseCase
{
    /**
     * @throws CommunicationCoverUnavailableException se la comunicazione non ha
     *                                                una copertina o il file non e' sullo storage.
     */
    public function download(int $communicationId): DownloadableCoverImage;
}
