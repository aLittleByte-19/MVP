<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Stato corrente di una comunicazione rilevante per il polling SSE della
 * generazione. Nessun riferimento a Eloquent.
 */
final class CommunicationProgressSnapshot
{
    public function __construct(
        public readonly string $generationStatus,
        public readonly string $coverStatus,
        public readonly ?string $generatedTitle,
        public readonly ?string $generatedBody,
        public readonly ?string $coverError,
        public readonly ?string $errorMessage,
    ) {}
}
