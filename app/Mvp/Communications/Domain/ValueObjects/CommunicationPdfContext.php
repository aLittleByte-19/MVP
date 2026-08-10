<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

final class CommunicationPdfContext
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly ?string $body,
        public readonly string $coverStatus,
        public readonly ?string $coverImagePath,
        public readonly ?string $coverImageMime,
    ) {}
}
