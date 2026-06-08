<?php

namespace App\Poc\Services;

use App\Poc\Enums\ProcessingStatus;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use App\Poc\Security\PocUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class DocumentProcessingService
{
    public function __construct(
        private readonly BedrockService $bedrock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws \RuntimeException when the upload cannot be persisted to the configured disk.
     */
    public function storeUpload(UploadedFile $file, PocUser $actor): OriginalDocument
    {
        $path = $file->store('documents/originals', $this->documentDisk());

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Impossibile salvare il documento nello storage configurato.');
        }

        $safeName = preg_replace('/[^\w.\-]/u', '_', $file->getClientOriginalName()) ?: 'documento.pdf';

        return $this->handleStoredFile($path, $safeName, $actor);
    }

    public function handleStoredFile(string $path, string $filename, ?PocUser $actor = null): OriginalDocument
    {
        return OriginalDocument::create([
            'tenant_id' => $actor?->tenantId ?? 'poc-local-tenant',
            'created_by' => $actor?->id,
            'file_path' => $path,
            'original_filename' => $filename,
            'processing_status' => ProcessingStatus::Pending,
        ]);
    }

    public function extractAndSaveFields(SubDocument $subDocument): void
    {
        try {
            $fields = $this->extractFields($subDocument->file_path);
            $subDocument->update(['error_message' => null]);
            ExtractedData::updateOrCreate(
                ['sub_document_id' => $subDocument->id],
                $fields,
            );
        } catch (\Throwable $e) {
            Log::error('DocumentProcessingService: extraction failed', [
                'sub_document_id' => $subDocument->id,
                'message' => $e->getMessage(),
            ]);
            $subDocument->update([
                'error_message' => BedrockService::formatUserError($e, 'Estrazione campi non disponibile. Verifica configurazione e permessi Bedrock.'),
            ]);

            throw $e;
        }
    }

    /**
     * Process each segment individually so the SSE stream can observe sub-documents
     * appearing one at a time as they are committed to the database.
     */
    public function process(OriginalDocument $original): void
    {
        $absoluteSource = null;

        try {
            $original->update([
                'processing_status' => ProcessingStatus::Processing,
                'error_message' => null,
            ]);
            $this->audit->record(
                'poc-document-processing-started',
                resourceType: 'original_document',
                resourceId: (string) $original->id,
                metadata: ['status' => ProcessingStatus::Processing->value],
                tenantId: $original->tenant_id,
            );

            $absoluteSource = $this->copyStorageFileToTemporaryPath($original->file_path);
            $pdf = new Fpdi;
            $pageCount = max(1, $pdf->setSourceFile($absoluteSource));

            $segments = $this->normalizeSegments(
                $this->splitDocument($original->file_path),
                $pageCount
            );

            $oldSplitPaths = $this->deleteExistingSplitRecords($original);
            $this->deleteStoragePaths($oldSplitPaths);

            foreach ($segments as $segment) {
                $preparedSegment = $this->prepareSplitSegment($original, $segment, $absoluteSource);
                try {
                    $subDocument = $this->createSubDocumentFromPreparedSegment($original, $preparedSegment);
                } catch (\Throwable $e) {
                    $this->deleteStoragePaths([$preparedSegment['file_path']]);

                    throw $e;
                }
                $this->extractAndSaveFields($subDocument);
            }

            $original->update([
                'processing_status' => ProcessingStatus::Completed,
                'error_message' => null,
            ]);
            $this->audit->record(
                'poc-document-processing-completed',
                resourceType: 'original_document',
                resourceId: (string) $original->id,
                metadata: ['status' => ProcessingStatus::Completed->value],
                tenantId: $original->tenant_id,
            );
        } catch (\Throwable $e) {
            $this->handleProcessingFailure($original, $e);
        } finally {
            if ($absoluteSource !== null) {
                File::delete($absoluteSource);
            }
        }
    }

    /**
     * Create the split PDF for a segment before any database mutation.
     *
     * @param  array{employee_name: string, start_page: int, end_page: int}  $segment
     * @return array{employee_name: string, start_page: int, end_page: int, file_path: string}
     */
    private function prepareSplitSegment(OriginalDocument $original, array $segment, string $absoluteSource): array
    {
        $splitPath = $this->extractPages(
            $absoluteSource,
            $original->id,
            $segment['employee_name'],
            (int) $segment['start_page'],
            (int) $segment['end_page']
        );

        return array_merge($segment, ['file_path' => $splitPath]);
    }

    /**
     * @param  array{employee_name: string, start_page: int, end_page: int, file_path: string}  $segment
     */
    private function createSubDocumentFromPreparedSegment(OriginalDocument $original, array $segment): SubDocument
    {
        return SubDocument::create([
            'original_document_id' => $original->id,
            'file_path' => $segment['file_path'],
            'start_page' => $segment['start_page'],
            'end_page' => $segment['end_page'],
        ]);
    }

    /**
     * Mark the document as failed, then rethrow so the queue can apply its retry policy.
     *
     * @throws \Throwable
     */
    private function handleProcessingFailure(OriginalDocument $original, \Throwable $e): void
    {
        Log::error('PDF Pipeline Failure', [
            'document_id' => $original->id,
            'error' => $e->getMessage(),
        ]);

        $original->update([
            'processing_status' => ProcessingStatus::Failed,
            'error_message' => BedrockService::formatUserError($e, 'Analisi documento non disponibile. Verifica configurazione e permessi Bedrock.'),
        ]);
        $this->audit->record(
            'poc-document-processing-failed',
            resourceType: 'original_document',
            resourceId: (string) $original->id,
            metadata: [
                'status' => ProcessingStatus::Failed->value,
                'message' => BedrockService::formatUserError($e, 'Analisi documento non disponibile. Verifica configurazione e permessi Bedrock.'),
            ],
            tenantId: $original->tenant_id,
        );

        throw $e;
    }

    /**
     * Delete existing sub-document records and return storage paths for cleanup after commit.
     *
     * @return array<int, string>
     */
    private function deleteExistingSplitRecords(OriginalDocument $original): array
    {
        $splits = $original->subDocuments()->get(['id', 'file_path']);
        $paths = $splits->pluck('file_path')->filter()->values()->all();

        $splits->each(function (SubDocument $split): void {
            $split->delete();
        });

        return $paths;
    }

    /**
     * Delete split PDFs from storage without failing an otherwise completed DB update.
     *
     * @param  array<int, string>  $paths
     */
    private function deleteStoragePaths(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            try {
                Storage::disk($this->documentDisk())->delete($path);
            } catch (\Throwable $e) {
                Log::warning('DocumentProcessingService: storage cleanup failed', [
                    'path' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{employee_name?: string, start_page?: int, end_page?: int}>  $segments
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function normalizeSegments(array $segments, int $pageCount): array
    {
        if ($segments === []) {
            throw new \RuntimeException('Il classificatore documentale non ha restituito segmenti elaborabili.');
        }

        return array_values(array_map(function (array $segment) use ($pageCount): array {
            $startPage = min($pageCount, max(1, (int) ($segment['start_page'] ?? 1)));
            $endPage = min($pageCount, max($startPage, (int) ($segment['end_page'] ?? $startPage)));

            return [
                'employee_name' => trim((string) ($segment['employee_name'] ?? 'documento')) ?: 'documento',
                'start_page' => $startPage,
                'end_page' => $endPage,
            ];
        }, $segments));
    }

    public function documentDisk(): string
    {
        return config('filesystems.default', 'local');
    }

    /**
     * Split the document using the configured Bedrock classifier.
     *
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function splitDocument(string $pdfPath): array
    {
        return $this->bedrock->splitDocument($pdfPath);
    }

    /**
     * Extract fields from the document using the configured Bedrock model.
     *
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function extractFields(string $subPdfPath): array
    {
        return $this->bedrock->extractFields($subPdfPath);
    }

    /**
     * Extract a page range from an already-resolved absolute path and write it to storage.
     * The caller is responsible for the lifecycle of $absoluteSource.
     *
     * @return string Relative path within the configured document disk
     */
    private function extractPages(string $absoluteSource, int $originalId, string $employeeName, int $startPage, int $endPage): string
    {
        $pdf = new Fpdi;
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
            $relativePath = "documents/sub/{$originalId}_{$slug}_{$startPage}-{$endPage}_".Str::uuid().'.pdf';

            $pdf->Output($absoluteDest, 'F');

            if (! Storage::disk($this->documentDisk())->put($relativePath, File::get($absoluteDest))) {
                throw new \RuntimeException("Impossibile salvare lo split PDF: {$relativePath}");
            }

            return $relativePath;
        } finally {
            File::delete($absoluteDest);
        }
    }

    /**
     * @throws \RuntimeException when the source file cannot be read from storage.
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
     * @throws \RuntimeException when the temporary file cannot be created.
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
