<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class SubDocumentDeleted
{
    public function __construct(
        public readonly int $subDocumentId,
        public readonly int $originalDocumentId,
        public readonly ?Actor $actor,
    ) {}
}
