<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class CommunicationGenerationRequested
{
    public function __construct(
        public readonly int $communicationId,
        public readonly Actor $actor,
        public readonly string $tone,
        public readonly string $style,
    ) {}
}
