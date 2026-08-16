<?php

namespace App\Mvp\Documents\Domain\Events;

final class DocumentProcessingFailed
{
    public function __construct(
        public readonly int $documentId,
        public readonly ?string $tenantId,
        public readonly string $message,
        public readonly bool $isInvalidAiOutput,
        public readonly ?string $aiOperation,
    ) {}
}
