<?php

namespace App\Mvp\Documents\Domain\Events;

final class DocumentProcessingCompleted
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId,
    ) {}
}
