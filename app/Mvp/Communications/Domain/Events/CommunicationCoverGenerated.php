<?php

namespace App\Mvp\Communications\Domain\Events;

final class CommunicationCoverGenerated
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly string $mime,
    ) {}
}
