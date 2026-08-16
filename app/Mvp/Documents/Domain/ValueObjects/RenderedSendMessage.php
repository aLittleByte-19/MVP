<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

final class RenderedSendMessage
{
    public function __construct(
        public readonly string $pdf,
        public readonly string $filename,
    ) {}
}
