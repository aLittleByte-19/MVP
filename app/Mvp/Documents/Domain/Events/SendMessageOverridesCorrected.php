<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class SendMessageOverridesCorrected
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(
        public readonly int $subDocumentId,
        public readonly ?Actor $actor,
        public readonly array $fields,
    ) {}
}
