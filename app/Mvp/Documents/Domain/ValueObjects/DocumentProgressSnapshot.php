<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Stato corrente di un documento originale rilevante per il polling SSE
 * (UC-35/UC-36). Nessun riferimento a Eloquent.
 */
final class DocumentProgressSnapshot
{
    /**
     * @param  list<int>  $extractedSubDocumentIds
     */
    public function __construct(
        public readonly string $processingStatus,
        public readonly ?string $errorMessage,
        public readonly array $extractedSubDocumentIds,
    ) {}
}
