<?php

namespace App\Poc\Jobs;

use App\Poc\Enums\ProcessingStatus;
use App\Poc\Models\OriginalDocument;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for processing an original document.
 */
class ProcessOriginalDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

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
        $documents->process($this->document->refresh());
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable|null  $exception
     * @return void
     */
    public function failed(?\Throwable $exception): void
    {
        $this->document->update(['processing_status' => ProcessingStatus::Failed]);

        Log::error('ProcessOriginalDocumentJob failed', [
            'original_id' => $this->document->id,
            'message' => $exception?->getMessage(),
        ]);
    }
}
