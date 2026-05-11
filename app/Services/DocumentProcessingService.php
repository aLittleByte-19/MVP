<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class DocumentProcessingService
{
    public function __construct(private readonly BedrockService $bedrock) {}

    /**
     * Store the uploaded PDF, trigger AI split, and persist SubDocuments.
     */
    public function handleUpload(UploadedFile $file): OriginalDocument
    {
        $filename = $file->getClientOriginalName();
        $path = $file->store('documents/originals', 'local');

        $original = OriginalDocument::create([
            'file_path' => $path,
            'original_filename' => $filename,
            'processing_status' => ProcessingStatus::Pending,
        ]);

        $this->process($original);

        return $original;
    }

    /**
     * Run AI split + physical PDF splitting for an OriginalDocument.
     */
    public function process(OriginalDocument $original): void
    {
        $original->update(['processing_status' => ProcessingStatus::Processing]);

        try {
            $segments = $this->bedrock->splitDocument($original->file_path);

            foreach ($segments as $segment) {
                $subPath = $this->extractPages(
                    $original->file_path,
                    $original->id,
                    $segment['employee_name'],
                    (int) $segment['start_page'],
                    (int) $segment['end_page'],
                );

                $subDocument = SubDocument::create([
                    'original_document_id' => $original->id,
                    'file_path' => $subPath,
                    'start_page' => $segment['start_page'],
                    'end_page' => $segment['end_page'],
                ]);

                try {
                    $fields = $this->bedrock->extractFields($subPath);
                    ExtractedData::create(array_merge(
                        ['sub_document_id' => $subDocument->id],
                        $fields,
                    ));
                } catch (\Throwable $e) {
                    Log::error('DocumentProcessingService: extraction failed', [
                        'sub_document_id' => $subDocument->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $original->update(['processing_status' => ProcessingStatus::Completed]);
        } catch (\Throwable $e) {
            Log::error('DocumentProcessingService: split failed', [
                'original_id' => $original->id,
                'message' => $e->getMessage(),
            ]);
            $original->update(['processing_status' => ProcessingStatus::Failed]);
            throw $e;
        }
    }

    /**
     * Extract a page range from a PDF and write it to storage.
     *
     * @return string Relative path within the local disk
     */
    private function extractPages(string $sourcePath, int $originalId, string $employeeName, int $startPage, int $endPage): string
    {
        $pdf = new Fpdi;

        $absoluteSource = Storage::disk('local')->path($sourcePath);
        $pageCount = $pdf->setSourceFile($absoluteSource);

        for ($page = $startPage; $page <= min($endPage, $pageCount); $page++) {
            $tplIdx = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tplIdx);
            $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $pdf->useTemplate($tplIdx);
        }

        $slug = preg_replace('/[^a-z0-9_]/i', '_', $employeeName);
        $relativePath = "documents/sub/{$originalId}_{$slug}.pdf";
        $absoluteDest = Storage::disk('local')->path($relativePath);

        Storage::disk('local')->makeDirectory('documents/sub');
        $pdf->Output($absoluteDest, 'F');

        return $relativePath;
    }
}
