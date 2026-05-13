<?php

namespace App\Poc\Jobs;

use App\Poc\Enums\ProcessingStatus;
use App\Poc\Models\OriginalDocument;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SplitDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public OriginalDocument $document) {}

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
