<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class SubDocumentDeleted
{
    public function __construct(
        public readonly int $subDocumentId,
        public readonly int $originalDocumentId,
        public readonly ?MvpUser $actor,
    ) {}
}
