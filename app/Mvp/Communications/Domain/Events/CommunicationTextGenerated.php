<?php

namespace App\Mvp\Communications\Domain\Events;

final class CommunicationTextGenerated
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly string $tone,
        public readonly string $style,
    ) {}
}
