<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class CommunicationRegenerationRequested
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly ?Actor $actor,
    ) {}
}
