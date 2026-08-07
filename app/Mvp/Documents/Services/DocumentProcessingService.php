<?php

namespace App\Mvp\Documents\Services;

use App\Exceptions\InvalidAiOutputException;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Identity\MvpUser;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
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
        private readonly WorkflowTaskHeartbeat $heartbeat,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * @param  array{
     *     document_type?: ?string,
     *     company_name?: ?string,
     *     reference_month?: ?int,
     *     reference_year?: ?int
     * }  $manualMetadata
     *
     * @throws \RuntimeException when the upload cannot be persisted to the configured disk.
     */
    public function storeUpload(UploadedFile $file, MvpUser $actor, array $manualMetadata = []): OriginalDocument
    {
        $path = $file->store('originals', $this->documentDisk());

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Impossibile salvare il documento nello storage configurato.');
        }

        $safeName = preg_replace('/[^\w.\-]/u', '_', $file->getClientOriginalName()) ?: 'documento.pdf';

        return $this->handleStoredFile($path, $safeName, $actor, $manualMetadata);
    }

    /**
     * @param  array{
     *     document_type?: ?string,
     *     company_name?: ?string,
     *     reference_month?: ?int,
     *     reference_year?: ?int
     * }  $manualMetadata
     */
    public function handleStoredFile(string $path, string $filename, ?MvpUser $actor = null, array $manualMetadata = []): OriginalDocument
    {
        return OriginalDocument::create([
            'tenant_id' => $actor?->tenantId ?? 'mvp-local-tenant',
            'created_by' => $actor?->id,
            'file_path' => $path,
            'original_filename' => $filename,
            'manual_document_type' => $manualMetadata['document_type'] ?? null,
            'manual_company_name' => $manualMetadata['company_name'] ?? null,
            'manual_reference_month' => $manualMetadata['reference_month'] ?? null,
            'manual_reference_year' => $manualMetadata['reference_year'] ?? null,
            'processing_status' => ProcessingStatus::Pending,
        ]);
    }

    public function extractAndSaveFields(SubDocument $subDocument): void
    {
        try {
            $aiFields = $this->extractFields($subDocument);
            // I metadati impostati in upload prevalgono sull'estrazione AI.
            $fields = $this->applyManualMetadataOverrides($aiFields, $subDocument->originalDocument);
            // La confidenza effettiva non è l'auto-valutazione del modello (non
            // calibrata), ma un valore oggettivo: leggibilità OCR × completezza
            // dei campi chiave. L'output grezzo del modello resta in ai_payload.
            // Si misura sull'estrazione grezza, non sui campi già sovrascritti
            // dai metadati di upload: la confidenza riguarda l'AI, non l'operatore.
            $confidenceScore = $this->computeConfidenceScore($aiFields, $subDocument);
            $reviewStatus = $this->reviewStatusForConfidence($confidenceScore);
            $subDocument->update([
                'review_status' => $reviewStatus,
                'error_message' => null,
            ]);
            ExtractedData::updateOrCreate(
                ['sub_document_id' => $subDocument->id],
                array_merge($fields, [
                    'confidence_score' => $confidenceScore,
                    'ai_payload' => $aiFields,
                ]),
            );
            $this->metrics->recordDomainCounter('ai_extractions_total', [
                'review_status' => $reviewStatus->value,
            ]);
            $this->audit->record(
                $reviewStatus === ReviewStatus::AutoValidated
                    ? 'mvp-sub-document-auto-validated'
                    : 'mvp-sub-document-needs-review',
                resourceType: 'sub_document',
                resourceId: (string) $subDocument->id,
                metadata: [
                    'confidence_score' => $confidenceScore,
                    'confidence_threshold' => $this->confidenceThreshold(),
                    'review_status' => $reviewStatus->value,
                ],
                tenantId: $subDocument->originalDocument?->tenant_id,
            );
        } catch (InvalidAiOutputException $e) {
            $safeMessage = 'Output AI non conforme: sotto-documento in quarantena.';
            Log::warning('DocumentProcessingService: AI output quarantined', [
                'sub_document_id' => $subDocument->id,
                'operation' => $e->operation(),
                'errors' => $e->errors(),
            ]);

            ExtractedData::query()
                ->where('sub_document_id', $subDocument->id)
                ->delete();
            $subDocument->update([
                'review_status' => ReviewStatus::Quarantined,
                'error_message' => $safeMessage,
            ]);
            $this->audit->record(
                'mvp-sub-document-ai-output-quarantined',
                resourceType: 'sub_document',
                resourceId: (string) $subDocument->id,
                metadata: [
                    'operation' => $e->operation(),
                    'errors' => $e->errors(),
                    'review_status' => ReviewStatus::Quarantined->value,
                ],
                tenantId: $subDocument->originalDocument?->tenant_id,
            );
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', [
                'operation' => $e->operation(),
            ]);
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
                'mvp-document-processing-started',
                resourceType: 'original_document',
                resourceId: (string) $original->id,
                metadata: ['status' => ProcessingStatus::Processing->value],
                tenantId: $original->tenant_id,
            );

            $absoluteSource = $this->copyStorageFileToTemporaryPath($original->file_path);
            $pdf = new Fpdi;
            $pageCount = max(1, $pdf->setSourceFile($absoluteSource));

            // La chiamata di split a Bedrock e' sincrona e puo' durare minuti:
            // si manda un heartbeat subito prima, e l'ASL prevede un
            // HeartbeatSeconds abbastanza ampio da coprirla (240s).
            $this->heartbeat->beat(force: true);

            $segments = $this->normalizeSegments(
                $this->splitDocument($original, $pageCount),
                $pageCount
            );

            $oldSplitPaths = $this->deleteExistingSplitRecords($original);
            $this->deleteStoragePaths($oldSplitPaths);

            foreach ($segments as $segment) {
                $this->heartbeat->beat();
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
                'mvp-document-processing-completed',
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

        // Un output AI non conforme non e' un problema di configurazione:
        // il messaggio utente deve distinguerlo dagli errori Bedrock/AWS.
        $userMessage = $e instanceof InvalidAiOutputException
            ? 'Il classificatore AI ha restituito un output non valido: il documento non può essere elaborato automaticamente.'
            : BedrockService::formatUserError($e, 'Analisi documento non disponibile. Verifica configurazione e permessi Bedrock.');

        $original->update([
            'processing_status' => ProcessingStatus::Failed,
            'error_message' => $userMessage,
        ]);
        $this->audit->record(
            'mvp-document-processing-failed',
            resourceType: 'original_document',
            resourceId: (string) $original->id,
            metadata: [
                'status' => ProcessingStatus::Failed->value,
                'message' => $userMessage,
            ],
            tenantId: $original->tenant_id,
        );

        if ($e instanceof InvalidAiOutputException) {
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', [
                'operation' => $e->operation(),
            ]);
        }

        throw $e;
    }

    /**
     * Objective confidence for an extraction: how legible the source was
     * (Textract OCR confidence for the recipient's pages) weighted by how many
     * of the key fields were actually extracted. Replaces the model's own
     * uncalibrated self-assessment.
     *
     * Misura la sola estrazione automatica, come da UC-39.10: i campi dichiarati
     * in upload dal consulente fanno fede e non vengono valutati, quindi restano
     * esclusi sia dai campi trovati sia dal totale. Dichiarare metadati non alza
     * il punteggio, determina solo su quali campi l'estrazione viene valutata.
     *
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}  $aiFields  Estrazione grezza del modello, senza i sovrascritti manuali.
     */
    private function computeConfidenceScore(array $aiFields, SubDocument $subDocument): int
    {
        $original = $subDocument->originalDocument;
        $keyFields = array_values(array_diff(
            ['employee_first_name', 'employee_last_name', 'company_name', 'document_date'],
            $this->manuallyDeclaredKeyFields($original),
        ));

        $ocrConfidence = $this->ocrConfidenceForRange(
            $original,
            (int) $subDocument->start_page,
            (int) $subDocument->end_page
        );

        // Nessun campo chiave a carico dell'estrazione automatica: non c'e' nulla
        // da valutare, resta la sola leggibilita' della scansione.
        if ($keyFields === []) {
            return max(0, min(100, (int) round($ocrConfidence)));
        }

        $found = 0;

        foreach ($keyFields as $key) {
            if (isset($aiFields[$key]) && trim((string) $aiFields[$key]) !== '') {
                $found++;
            }
        }

        $completeness = $found / count($keyFields);

        return max(0, min(100, (int) round($ocrConfidence * $completeness)));
    }

    /**
     * Campi chiave gia' dichiarati in upload, che non sono piu' a carico
     * dell'estrazione automatica. `document_type` non compare fra questi:
     * non rientra nei campi chiave della confidenza.
     *
     * @return list<string>
     */
    private function manuallyDeclaredKeyFields(?OriginalDocument $original): array
    {
        if ($original === null) {
            return [];
        }

        $declared = [];

        if ($original->manual_company_name !== null) {
            $declared[] = 'company_name';
        }

        // E' sufficiente che sia dichiarato uno dei due: la data risultante non
        // deriva piu' dalla sola estrazione automatica.
        if ($original->manual_reference_month !== null || $original->manual_reference_year !== null) {
            $declared[] = 'document_date';
        }

        return $declared;
    }

    /**
     * Average Textract OCR confidence (0-100) over the recipient's page range,
     * falling back to the document-level average.
     */
    private function ocrConfidenceForRange(?OriginalDocument $original, int $startPage, int $endPage): float
    {
        if ($original === null) {
            return 0.0;
        }

        $pages = $original->ocr_pages;

        if (is_array($pages) && $pages !== []) {
            $values = [];

            foreach ($pages as $page) {
                $number = (int) ($page['page'] ?? 0);

                if ($number < $startPage || $number > $endPage) {
                    continue;
                }

                if (isset($page['confidence_avg']) && $page['confidence_avg'] !== null) {
                    $values[] = (float) $page['confidence_avg'];
                }
            }

            if ($values !== []) {
                return array_sum($values) / count($values);
            }
        }

        return (float) ($original->ocr_confidence_avg ?? 0.0);
    }

    private function reviewStatusForConfidence(?int $confidenceScore): ReviewStatus
    {
        if ($confidenceScore !== null && $confidenceScore >= $this->confidenceThreshold()) {
            return ReviewStatus::AutoValidated;
        }

        return ReviewStatus::NeedsReview;
    }

    private function confidenceThreshold(): int
    {
        return max(0, min(100, (int) config('services.bedrock.mvp_confidence_threshold', 80)));
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
        // Garanzia di scope: un documento reale ha sempre almeno un destinatario.
        // Se il classificatore non ne individua, l'intero documento è un unico
        // destinatario (rilevamento corretto, non un fallback automatico).
        if ($segments === []) {
            return [[
                'employee_name' => 'documento',
                'start_page' => 1,
                'end_page' => max(1, $pageCount),
            ]];
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
        return (string) config('mvp.documents.storage_disk', config('filesystems.default', 'local'));
    }

    /**
     * Split the document by feeding the Textract OCR text to the Bedrock classifier.
     *
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function splitDocument(OriginalDocument $original, int $pageCount): array
    {
        // Boundary "canary" casuale per-run: una stringa che non può comparire
        // nel testo del documento, così i marcatori di pagina non sono
        // confondibili con contenuto reale (es. un documento che cita "Pagina 2").
        $boundaryNonce = $this->pageBoundaryNonce();
        $ocrText = $this->ocrTextForRange($original, 1, $pageCount, $boundaryNonce);

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile: Textract deve completare l\'estrazione prima dell\'analisi.');
        }

        return $this->bedrock->splitDocument($ocrText, $pageCount, $boundaryNonce);
    }

    /**
     * Extract fields for a recipient from the OCR text of its page range.
     *
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function extractFields(SubDocument $subDocument): array
    {
        $original = $subDocument->originalDocument;

        if ($original === null) {
            throw new \RuntimeException('Documento originale non disponibile per il sotto-documento.');
        }

        $ocrText = $this->ocrTextForRange($original, (int) $subDocument->start_page, (int) $subDocument->end_page, $this->pageBoundaryNonce());

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile per l\'intervallo di pagine del destinatario.');
        }

        return $this->bedrock->extractFields($ocrText);
    }

    /**
     * Tipologia, azienda e mese/anno impostati in upload restano autoritativi.
     * L'AI continua a produrre l'estrazione (e il payload grezzo), ma non sovrascrive
     * i campi già dichiarati dal consulente.
     *
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}  $fields
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function applyManualMetadataOverrides(array $fields, ?OriginalDocument $original): array
    {
        if ($original === null || ! $original->hasManualUploadMetadata()) {
            return $fields;
        }

        if ($original->manual_document_type !== null) {
            $fields['document_type'] = $original->manual_document_type;
        }

        if ($original->manual_company_name !== null) {
            $fields['company_name'] = $original->manual_company_name;
        }

        $month = $original->manual_reference_month;
        $year = $original->manual_reference_year;

        if ($month !== null && $year !== null) {
            $fields['document_date'] = sprintf('%04d-%02d-01', $year, $month);
        } elseif ($year !== null || $month !== null) {
            $fields['document_date'] = $this->mergeManualDateWithAi($fields['document_date'] ?? null, $month, $year);
        }

        return $fields;
    }

    /**
     * Completa mese o anno mancante usando la data AI quando disponibile.
     */
    private function mergeManualDateWithAi(?string $aiDate, ?int $month, ?int $year): ?string
    {
        $aiYear = null;
        $aiMonth = null;

        if (is_string($aiDate) && preg_match('/^(\d{4})-(\d{2})/', $aiDate, $matches) === 1) {
            $aiYear = (int) $matches[1];
            $aiMonth = (int) $matches[2];
        }

        $resolvedYear = $year ?? $aiYear;
        $resolvedMonth = $month ?? $aiMonth;

        if ($resolvedYear === null || $resolvedMonth === null) {
            return $aiDate;
        }

        return sprintf('%04d-%02d-01', $resolvedYear, $resolvedMonth);
    }

    /**
     * Random per-run boundary token used to delimit pages in the OCR text fed to
     * the classifier. Unguessable so it cannot collide with document content.
     */
    private function pageBoundaryNonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Build page-delimited OCR text for a page range, using the per-page OCR when
     * available and falling back to the flat ocr_text for the whole document.
     * Each page is prefixed by a canary boundary marker keyed on $boundaryNonce.
     */
    private function ocrTextForRange(OriginalDocument $original, int $startPage, int $endPage, string $boundaryNonce): string
    {
        $pages = $original->ocr_pages;

        if (is_array($pages) && $pages !== []) {
            $parts = [];

            foreach ($pages as $page) {
                $number = (int) ($page['page'] ?? 0);

                if ($number < $startPage || $number > $endPage) {
                    continue;
                }

                $text = trim((string) ($page['text'] ?? ''));

                if ($text !== '') {
                    $parts[] = BedrockService::pageBoundaryMarker($number, $boundaryNonce)."\n".$text;
                }
            }

            if ($parts !== []) {
                return implode("\n\n", $parts);
            }
        }

        return trim((string) $original->ocr_text);
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
            $relativePath = "sub/{$originalId}_{$slug}_{$startPage}-{$endPage}_".Str::uuid().'.pdf';

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
        $directory = storage_path('app/tmp/mvp-processing');
        File::ensureDirectoryExists($directory);

        $path = tempnam($directory, $prefix);

        if ($path === false) {
            throw new \RuntimeException('Impossibile creare un file temporaneo per il processamento PDF.');
        }

        return $path;
    }
}
