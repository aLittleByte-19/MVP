<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Proiezione di dominio di una Communication. Nessun riferimento a Eloquent:
 * e' il tipo che CommunicationRepository restituisce ai casi d'uso, non il
 * model. Non e' un doppione ridondante di Communication: e' il contratto di
 * idratazione condiviso fra Communication::fromRecord() consumato
 * dall'adapter reale (EloquentCommunicationRepository) *e* dal fake usato
 * nei test di dominio puro (InMemoryCommunicationRepository) — entrambi
 * costruiscono lo stesso Record per idratare l'entita', senza che il
 * dominio sappia quale dei due lo sta chiamando.
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
