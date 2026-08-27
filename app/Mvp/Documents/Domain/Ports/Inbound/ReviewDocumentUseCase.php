<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\Exceptions\MissingExtractedDataException;
use App\Mvp\Support\Identity\Actor;

/**
 * Porta primaria: revisione human-in-the-loop dei dati estratti (UC-9bis e
 * derivati) — correzione dei campi e validazione manuale.
 */
interface ReviewDocumentUseCase
{
    /**
     * @param  array<string, mixed>  $fieldUpdates  Colonne di extracted_data gia' mappate (snake_case).
     * @return string Il nuovo review_status.
     */
    public function updateExtractedData(int $subDocumentId, array $fieldUpdates, bool $markAsValidated, Actor $actor): string;

    /**
     * @throws MissingExtractedDataException se non ci sono dati estratti da validare.
     */
    public function markReviewed(int $subDocumentId, Actor $actor): void;
}
