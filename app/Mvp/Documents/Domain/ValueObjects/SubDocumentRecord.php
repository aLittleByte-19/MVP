<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Proiezione di dominio di un SubDocument. Nessun riferimento a Eloquent:
 * e' il contratto di idratazione condiviso fra SubDocument::fromRecord()
 * consumato dall'adapter reale (EloquentDocumentRepository) *e* dal fake
 * usato nei test di dominio puro (InMemoryDocumentRepository) — non un
 * doppione ridondante di SubDocument.
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
        public readonly string $sendStatus = 'pending',
    ) {}
}
