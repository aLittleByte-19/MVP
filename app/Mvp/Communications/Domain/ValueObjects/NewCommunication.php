<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;

/**
 * Dati per creare una nuova Communication. A differenza di
 * CommunicationChanges (aggiornamento parziale), qui tutti i campi sono
 * noti al momento della creazione: un costruttore semplice basta (vedi
 * ADR 0010 e NewOriginalDocument, stesso pattern sul lato Documents).
 */
final class NewCommunication
{
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $createdBy,
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
        public readonly CommunicationGenerationStatus $generationStatus,
        public readonly CoverImageStatus $coverStatus,
        public readonly CommunicationStatus $status,
        public readonly bool $isFavorite,
    ) {}

    /**
     * @internal consumato solo dall'adapter di persistenza.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'created_by' => $this->createdBy,
            'prompt' => $this->prompt,
            'tone' => $this->tone,
            'style' => $this->style,
            'generation_status' => $this->generationStatus,
            'cover_status' => $this->coverStatus,
            'status' => $this->status,
            'is_favorite' => $this->isFavorite,
        ];
    }
}
