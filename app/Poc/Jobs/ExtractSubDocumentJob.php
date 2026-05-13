<?php

namespace App\Poc\Jobs;

use App\Poc\Models\SubDocument;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractSubDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public SubDocument $subDocument) {}

    public function handle(DocumentProcessingService $documents): void
    {
        $documents->extractAndSaveFields($this->subDocument);
    }
}
