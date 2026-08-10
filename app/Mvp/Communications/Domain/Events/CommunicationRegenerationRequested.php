<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class CommunicationRegenerationRequested
{
    public function __construct(
        public readonly int $communicationId,
        public readonly string $tenantId,
        public readonly ?MvpUser $actor,
    ) {}
}
