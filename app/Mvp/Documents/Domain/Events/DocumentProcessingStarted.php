<?php

namespace App\Mvp\Documents\Domain\Events;

final class DocumentProcessingStarted
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $tenantId,
    ) {}
}
