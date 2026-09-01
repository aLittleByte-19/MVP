<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Proiezione di dominio di una Communication, restituita da
 * CommunicationRepository ai casi d'uso — nessun riferimento a Eloquent.
 * E' il contratto di idratazione condiviso fra Communication::fromRecord()
 * e sia l'adapter reale (EloquentCommunicationRepository) che il fake dei
 * test (InMemoryCommunicationRepository).
 */
final class CommunicationRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $tenantId,
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
        public readonly ?string $generatedTitle,
        public readonly ?string $generatedBody,
        public readonly ?string $imagePrompt,
        public readonly string $generationStatus,
        public readonly ?string $coverImagePath,
        public readonly ?string $coverImageMime,
        public readonly string $coverStatus,
        public readonly string $status,
        public readonly bool $isFavorite,
        public readonly ?int $rating,
        public readonly ?string $workflowExecutionArn,
        public readonly ?string $coverError,
        public readonly ?string $errorMessage,
    ) {}
}
