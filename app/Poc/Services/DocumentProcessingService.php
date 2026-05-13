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

/**
 * Service for handling document processing including uploads, splitting, and data extraction.
 */
class DocumentProcessingService
{
    /**
     * Create a new service instance.
     *
     * @param  \App\Poc\Services\BedrockService  $bedrock
     * @return void
     */
    public function __construct(private readonly BedrockService $bedrock) {}

    /**
     * Store the uploaded PDF without starting the processing pipeline.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return \App\Poc\Models\OriginalDocument
     *
     * @throws \RuntimeException
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
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return \App\Poc\Models\OriginalDocument
     */
    public function handleUpload(UploadedFile $file): OriginalDocument
    {
        $original = $this->storeUpload($file);
        $this->process($original);

        return $original;
    }

    /**
     * Create an OriginalDocument from a file that is already stored on the document disk.
     *
     * @param  string  $path
     * @param  string  $filename
     * @return \App\Poc\Models\OriginalDocument
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
     * Extract fields from a single sub-document and persist to ExtractedData.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
     * @return void
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
     * Run the full AI pipeline: split the PDF and extract fields for each segment.
     *
     * @param  \App\Poc\Models\OriginalDocument  $original
     * @return void
     */
    public function process(OriginalDocument $original): void
    {
        $original->update(['processing_status' => ProcessingStatus::Processing]);

        try {
            $segments = $this->analyzeDocumentStructure($original);

            DB::transaction(function () use ($segments, $original): void {
                $this->cleanupExistingSplits($original);

                foreach ($segments as $segment) {
                    $subDocument = $this->createSubDocumentFromSegment($original, $segment);
                    $this->runDataExtraction($subDocument);
                }
            });

            $original->update(['processing_status' => ProcessingStatus::Completed]);
        } catch (\Throwable $e) {
            $this->handleProcessingFailure($original, $e);
        }
    }

    /**
     * Analyze the document structure and return segments.
     *
     * @param  \App\Poc\Models\OriginalDocument  $original
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function analyzeDocumentStructure(OriginalDocument $original): array
    {
        return $this->normalizeSegments(
            $this->splitDocument($original->file_path),
            $original->file_path
        );
    }

    /**
     * Create a SubDocument from a segment.
     *
     * @param  \App\Poc\Models\OriginalDocument  $original
     * @param  array{employee_name: string, start_page: int, end_page: int}  $segment
     * @return \App\Poc\Models\SubDocument
     */
    private function createSubDocumentFromSegment(OriginalDocument $original, array $segment): SubDocument
    {
        $splitPath = $this->extractPages(
            $original->file_path,
            $original->id,
            $segment['employee_name'],
            (int) $segment['start_page'],
            (int) $segment['end_page']
        );

        return SubDocument::create([
            'original_document_id' => $original->id,
            'file_path' => $splitPath,
            'start_page' => $segment['start_page'],
            'end_page' => $segment['end_page'],
        ]);
    }

    /**
     * Run data extraction for a sub-document.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
     * @return void
     */
    private function runDataExtraction(SubDocument $subDocument): void
    {
        try {
            $fields = $this->extractFields($subDocument->file_path);

            ExtractedData::create(array_merge(
                ['sub_document_id' => $subDocument->id],
                $fields
            ));
        } catch (\Throwable $e) {
            Log::warning("Extraction failed for split {$subDocument->id}", ['error' => $e->getMessage()]);
            $this->createEmptyExtractedData($subDocument);
        }
    }

    /**
     * Handle processing failure for an original document.
     *
     * @param  \App\Poc\Models\OriginalDocument  $original
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    private function handleProcessingFailure(OriginalDocument $original, \Throwable $e): void
    {
        Log::error('PDF Pipeline Failure', [
            'document_id' => $original->id,
            'error' => $e->getMessage(),
        ]);

        $original->update(['processing_status' => ProcessingStatus::Failed]);

        throw $e;
    }

    /**
     * Cleanup existing sub-documents for an original document.
     *
     * @param  \App\Poc\Models\OriginalDocument  $original
     * @return void
     */
    private function cleanupExistingSplits(OriginalDocument $original): void
    {
        $original->subDocuments->each(function (SubDocument $split): void {
            Storage::disk($this->documentDisk())->delete($split->file_path);
            $split->delete();
        });
    }

    /**
     * Keep the PoC useful even when the split model cannot identify multiple recipients:
     * one fallback segment still allows field extraction on the uploaded PDF.
     *
     * @param  array<int, array{employee_name?: string, start_page?: int, end_page?: int}>  $segments
     * @param  string  $sourcePath
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

    /**
     * Create an empty ExtractedData record for a sub-document.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
     * @return void
     */
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
     * Get the configured document disk.
     *
     * @return string
     */
    public function documentDisk(): string
    {
        return config('filesystems.default', 'local');
    }

    /**
     * Split the document using the configured classifier.
     *
     * @param  string  $pdfPath
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
     * Extract fields from the document using the configured OCR driver.
     *
     * @param  string  $subPdfPath
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
     * @param  string  $sourcePath
     * @param  int  $originalId
     * @param  string  $employeeName
     * @param  int  $startPage
     * @param  int  $endPage
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

    /**
     * Get the page count of a PDF.
     *
     * @param  string  $sourcePath
     * @return int
     */
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

    /**
     * Copy a file from storage to a temporary path.
     *
     * @param  string  $storagePath
     * @return string
     *
     * @throws \RuntimeException
     */
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

    /**
     * Create a temporary path for a file.
     *
     * @param  string  $prefix
     * @return string
     *
     * @throws \RuntimeException
     */
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
}
