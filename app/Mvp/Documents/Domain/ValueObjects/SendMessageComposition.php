<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

final class SendMessageComposition
{
    public function __construct(
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $companyName,
        public readonly string $attachmentFilename,
    ) {}
}
