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
        $path = $file->store('documents/originals', 'local');

        return $this->handleStoredFile($path, $file->getClientOriginalName());
    }

    /**
     * Create an OriginalDocument from a file that is already stored on the local disk.
     */
    public function handleStoredFile(string $path, string $filename): OriginalDocument
    {
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
            $segments = $this->normalizeSegments($this->splitDocument($original->file_path), $original->file_path);

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
                    $fields = $this->extractFields($subPath);
                    ExtractedData::create(array_merge(
                        ['sub_document_id' => $subDocument->id],
                        $fields,
                    ));
                } catch (\Throwable $e) {
                    Log::error('DocumentProcessingService: extraction failed', [
                        'sub_document_id' => $subDocument->id,
                        'message' => $e->getMessage(),
                    ]);

                    $this->createEmptyExtractedData($subDocument);
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
     * Keep the PoC useful even when the split model cannot identify multiple recipients:
     * one fallback segment still allows field extraction on the uploaded PDF.
     *
     * @param  array<int, array{employee_name?: string, start_page?: int, end_page?: int}>  $segments
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function normalizeSegments(array $segments, string $sourcePath): array
    {
        $pageCount = $this->pageCount($sourcePath);

        if ($segments === []) {
            return [[
                'employee_name' => 'documento',
                'start_page' => 1,
                'end_page' => $pageCount,
            ]];
        }

        return array_values(array_map(function (array $segment) use ($pageCount): array {
            $startPage = max(1, (int) ($segment['start_page'] ?? 1));
            $endPage = min($pageCount, max($startPage, (int) ($segment['end_page'] ?? $startPage)));

            return [
                'employee_name' => trim((string) ($segment['employee_name'] ?? 'documento')) ?: 'documento',
                'start_page' => $startPage,
                'end_page' => $endPage,
            ];
        }, $segments));
    }

    private function createEmptyExtractedData(SubDocument $subDocument): void
    {
        ExtractedData::create([
            'sub_document_id' => $subDocument->id,
            'employee_first_name' => null,
            'employee_last_name' => null,
            'company_name' => null,
            'document_date' => null,
            'document_type' => null,
            'description' => null,
            'confidence_score' => null,
        ]);
    }

    /**
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function splitDocument(string $pdfPath): array
    {
        if (config('services.documents.classifier_driver', 'fake') === 'fake') {
            return [
                ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 1],
            ];
        }

        return $this->bedrock->splitDocument($pdfPath);
    }

    /**
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function extractFields(string $subPdfPath): array
    {
        if (config('services.documents.classifier_driver', 'fake') === 'fake') {
            return [
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Azienda Demo Srl',
                'document_date' => now()->toDateString(),
                'document_type' => 'Cedolino',
                'description' => 'Dati estratti in modalita PoC.',
                'confidence_score' => (int) config('services.bedrock.poc_confidence_threshold', 80),
            ];
        }

        return $this->bedrock->extractFields($subPdfPath);
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
        $relativePath = "documents/sub/{$originalId}_{$slug}_{$startPage}-{$endPage}.pdf";
        $absoluteDest = Storage::disk('local')->path($relativePath);

        Storage::disk('local')->makeDirectory('documents/sub');
        $pdf->Output($absoluteDest, 'F');

        return $relativePath;
    }

    private function pageCount(string $sourcePath): int
    {
        $pdf = new Fpdi;

        return max(1, $pdf->setSourceFile(Storage::disk('local')->path($sourcePath)));
    }
}
