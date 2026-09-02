<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

final class RenderedCommunicationPdf
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $filename,
    ) {}
}
