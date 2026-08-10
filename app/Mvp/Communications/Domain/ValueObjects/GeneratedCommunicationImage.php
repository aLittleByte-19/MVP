<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

final class GeneratedCommunicationImage
{
    public function __construct(
        public readonly ?string $bytes,
        public readonly string $mime,
        public readonly ?string $warning,
        public readonly ?string $reason,
    ) {}
}
