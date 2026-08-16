<?php

namespace App\Mvp\Documents\Domain\Events;

final class AiOutputRejected
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly int $subDocumentId,
        public readonly string $tenantId,
        public readonly string $operation,
        public readonly array $errors,
    ) {}
}
