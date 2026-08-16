<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class DocumentUploadAccepted
{
    /**
     * @param  array<string, mixed>  $manualMetadata
     */
    public function __construct(
        public readonly int $documentId,
        public readonly Actor $actor,
        public readonly string $filename,
        public readonly array $manualMetadata,
    ) {}
}
