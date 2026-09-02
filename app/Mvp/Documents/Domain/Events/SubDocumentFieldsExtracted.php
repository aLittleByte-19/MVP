<?php

namespace App\Mvp\Documents\Domain\Events;

final class SubDocumentFieldsExtracted
{
    public function __construct(
        public readonly int $subDocumentId,
        public readonly string $tenantId,
        public readonly string $reviewStatus,
        public readonly ?int $confidenceScore,
        public readonly int $confidenceThreshold,
    ) {}
}
