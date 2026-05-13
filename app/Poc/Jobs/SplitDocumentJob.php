<?php

namespace App\Poc\Jobs;

use App\Poc\Enums\ProcessingStatus;
use App\Poc\Models\OriginalDocument;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job for splitting an original document into sub-documents.
 */
class SplitDocumentJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param  \App\Poc\Models\OriginalDocument  $document
     * @return void
     */
    public function __construct(public OriginalDocument $document) {}

    /**
     * Execute the job.
     *
     * @param  \App\Poc\Services\DocumentProcessingService  $documents
     * @return void
     */
    public function handle(DocumentProcessingService $documents): void
    {
        try {
            $documents->process($this->document->refresh());
        } catch (\Throwable $e) {
            Log::error('SplitDocumentJob failed', [
                'original_id' => $this->document->id,
                'message' => $e->getMessage(),
            ]);
            $this->document->update(['processing_status' => ProcessingStatus::Failed]);
            throw $e;
        }
    }
}
