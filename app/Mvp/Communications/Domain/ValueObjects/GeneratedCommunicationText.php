<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

final class GeneratedCommunicationText
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $imagePrompt,
    ) {}
}
