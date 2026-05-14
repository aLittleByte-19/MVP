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

class ProcessOriginalDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    public function __construct(public OriginalDocument $document) {}

    public function handle(DocumentProcessingService $documents): void
    {
        $documents->process($this->document->refresh());
    }

    public function failed(?\Throwable $exception): void
    {
        $this->document->update(['processing_status' => ProcessingStatus::Failed]);

        Log::error('ProcessOriginalDocumentJob failed', [
            'original_id' => $this->document->id,
            'message' => $exception?->getMessage(),
        ]);
    }
}
