<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Proiezione di dominio di un SubDocument. Nessun riferimento a Eloquent.
 */
final class SubDocumentRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $originalDocumentId,
        public readonly string $filePath,
        public readonly int $startPage,
        public readonly int $endPage,
        public readonly string $originalFilename,
    ) {}
}
