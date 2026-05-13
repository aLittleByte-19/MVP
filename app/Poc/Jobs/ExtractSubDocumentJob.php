<?php

namespace App\Poc\Jobs;

use App\Poc\Models\SubDocument;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job for extracting data from a sub-document.
 */
class ExtractSubDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
     * @return void
     */
    public function __construct(public SubDocument $subDocument) {}

    /**
     * Execute the job.
     *
     * @param  \App\Poc\Services\DocumentProcessingService  $documents
     * @return void
     */
    public function handle(DocumentProcessingService $documents): void
    {
        $documents->extractAndSaveFields($this->subDocument);
    }
}
