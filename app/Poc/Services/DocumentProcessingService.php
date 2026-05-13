<?php

namespace App\Poc\Services;

use App\Poc\Enums\ProcessingStatus;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

class DocumentProcessingService
{
    public function __construct(private readonly BedrockService $bedrock) {}

    /**
     * Store the uploaded PDF without starting the processing pipeline.
     */
    public function storeUpload(UploadedFile $file): OriginalDocument
    {
        $path = $file->store('documents/originals', $this->documentDisk());

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Impossibile salvare il documento nello storage configurato.');
        }

        $safeName = preg_replace('/[^\w.\-]/u', '_', $file->getClientOriginalName()) ?: 'documento.pdf';

        return $this->handleStoredFile($path, $safeName);
    }

    /**
     * Store the uploaded PDF, trigger AI split, and persist SubDocuments.
     */
    public function handleUpload(UploadedFile $file): OriginalDocument
    {
        $original = $this->storeUpload($file);
        $this->process($original);

        return $original;
    }

    /**
     * Create an OriginalDocument from a file that is already stored on the document disk.
     */
    public function handleStoredFile(string $path, string $filename): OriginalDocument
    {
        return OriginalDocument::create([
            'file_path' => $path,
            'original_filename' => $filename,
            'processing_status' => ProcessingStatus::Pending,
        ]);
    }

    /**
     * Split a document into sub-documents without field extraction.
     *
     * @return SubDocument[]
     */
    public function splitIntoSubDocuments(OriginalDocument $original): array
    {
        $segments = $this->normalizeSegments(
            $this->splitDocument($original->file_path),
            $original->file_path,
        );

        $subDocuments = [];
        foreach ($segments as $segment) {
            $subPath = $this->extractPages(
                $original->file_path,
                $original->id,
                $segment['employee_name'],
                (int) $segment['start_page'],
                (int) $segment['end_page'],
            );

            $subDocuments[] = SubDocument::create([
                'original_document_id' => $original->id,
                'file_path' => $subPath,
                'start_page' => $segment['start_page'],
                'end_page' => $segment['end_page'],
            ]);
        }

        return $subDocuments;
    }

    /**
     * Extract fields from a single sub-document and persist to ExtractedData.
     */
    public function extractAndSaveFields(SubDocument $subDocument): void
    {
        try {
            $fields = $this->extractFields($subDocument->file_path);
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

    /**
     * Run AI split + physical PDF splitting for an OriginalDocument.
     */
    public function process(OriginalDocument $original): void
    {
        $original->update(['processing_status' => ProcessingStatus::Processing]);

        try {
            $segments = $this->normalizeSegments($this->splitDocument($original->file_path), $original->file_path);

            DB::transaction(function () use ($segments, $original): void {
                $this->deleteSubDocuments($original);

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
            });

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

    public function documentDisk(): string
    {
        return config('filesystems.default', 'local');
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
     * @return string Relative path within the configured document disk
     */
    private function extractPages(string $sourcePath, int $originalId, string $employeeName, int $startPage, int $endPage): string
    {
        $pdf = new Fpdi;
        $absoluteSource = $this->copyStorageFileToTemporaryPath($sourcePath);
        $absoluteDest = $this->temporaryPath('split_');

        try {
            $pageCount = $pdf->setSourceFile($absoluteSource);

            for ($page = $startPage; $page <= min($endPage, $pageCount); $page++) {
                $tplIdx = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($tplIdx);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tplIdx);
            }

            $slug = preg_replace('/[^a-z0-9_]/i', '_', $employeeName) ?: 'documento';
            $relativePath = "documents/sub/{$originalId}_{$slug}_{$startPage}-{$endPage}.pdf";

            $pdf->Output($absoluteDest, 'F');

            if (! Storage::disk($this->documentDisk())->put($relativePath, File::get($absoluteDest))) {
                throw new \RuntimeException("Impossibile salvare lo split PDF: {$relativePath}");
            }

            return $relativePath;
        } finally {
            File::delete([$absoluteSource, $absoluteDest]);
        }
    }

    private function pageCount(string $sourcePath): int
    {
        $pdf = new Fpdi;
        $absoluteSource = $this->copyStorageFileToTemporaryPath($sourcePath);

        try {
            return max(1, $pdf->setSourceFile($absoluteSource));
        } finally {
            File::delete($absoluteSource);
        }
    }

    private function copyStorageFileToTemporaryPath(string $storagePath): string
    {
        $contents = Storage::disk($this->documentDisk())->get($storagePath);

        if ($contents === null || $contents === false) {
            throw new \RuntimeException("File non trovato sullo storage documenti: {$storagePath}");
        }

        $temporaryPath = $this->temporaryPath('source_');
        File::put($temporaryPath, $contents);

        return $temporaryPath;
    }

    private function temporaryPath(string $prefix): string
    {
        $directory = storage_path('app/tmp/poc-processing');
        File::ensureDirectoryExists($directory);

        $path = tempnam($directory, $prefix);

        if ($path === false) {
            throw new \RuntimeException('Impossibile creare un file temporaneo per il processamento PDF.');
        }

        return $path;
    }

    private function deleteSubDocuments(OriginalDocument $original): void
    {
        $original->subDocuments()
            ->get()
            ->each(function (SubDocument $subDocument): void {
                Storage::disk($this->documentDisk())->delete($subDocument->file_path);
                $subDocument->delete();
            });
    }
}
