<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

/**
 * Applicazione: divide un documento originale nei suoi destinatari (Bedrock)
 * ed estrae i campi di ciascuno, un segmento alla volta. Sostituisce
 * DocumentProcessingService::process()/extractAndSaveFields(): stessa logica,
 * ma orchestrata attraverso le porte del dominio invece che Eloquent/Storage
 * diretti. La manipolazione PDF (Fpdi) e i file temporanei restano qui:
 * sono dettagli di libreria senza alternativa in valutazione, non un
 * collaboratore da isolare dietro una porta (vedi ADR 0010).
 */
class ProcessDocumentService implements ProcessDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
        private readonly DocumentAiGatewayPort $ai,
        private readonly AuditLogger $audit,
        private readonly WorkflowTaskHeartbeat $heartbeat,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function process(int $documentId): void
    {
        $absoluteSource = null;

        try {
            $this->documents->updateOriginalDocument($documentId, [
                'processing_status' => ProcessingStatus::Processing,
                'error_message' => null,
            ]);
            $original = $this->documents->findOriginalDocument($documentId);
            $this->audit->record(
                'mvp-document-processing-started',
                resourceType: 'original_document',
                resourceId: (string) $documentId,
                metadata: ['status' => ProcessingStatus::Processing->value],
                tenantId: $original->tenantId,
            );

            $absoluteSource = $this->copyStorageFileToTemporaryPath($original->filePath);
            $pdf = new Fpdi;
            $pageCount = max(1, $pdf->setSourceFile($absoluteSource));

            // La chiamata di split a Bedrock e' sincrona e puo' durare minuti:
            // si manda un heartbeat subito prima, e l'ASL prevede un
            // HeartbeatSeconds abbastanza ampio da coprirla (240s).
            $this->heartbeat->beat(force: true);

            $segments = $this->normalizeSegments($this->splitDocument($original, $pageCount), $pageCount);

            $oldSplitPaths = $this->documents->deleteExistingSubDocuments($documentId);
            $this->deleteStoragePaths($oldSplitPaths);

            foreach ($segments as $segment) {
                $this->heartbeat->beat();
                $splitPath = $this->extractPages($absoluteSource, $documentId, $segment['employee_name'], $segment['start_page'], $segment['end_page']);

                try {
                    $subDocumentId = $this->documents->createSubDocument([
                        'original_document_id' => $documentId,
                        'file_path' => $splitPath,
                        'start_page' => $segment['start_page'],
                        'end_page' => $segment['end_page'],
                    ]);
                } catch (\Throwable $e) {
                    $this->deleteStoragePaths([$splitPath]);

                    throw $e;
                }

                $this->extractAndSaveFieldsWithContext($subDocumentId, $original);
            }

            $this->documents->updateOriginalDocument($documentId, [
                'processing_status' => ProcessingStatus::Completed,
                'error_message' => null,
            ]);
            $this->audit->record(
                'mvp-document-processing-completed',
                resourceType: 'original_document',
                resourceId: (string) $documentId,
                metadata: ['status' => ProcessingStatus::Completed->value],
                tenantId: $original->tenantId,
            );
        } catch (\Throwable $e) {
            $this->handleProcessingFailure($documentId, $e);
        } finally {
            if ($absoluteSource !== null) {
                @unlink($absoluteSource);
            }
        }
    }

    public function extractAndSaveFields(int $subDocumentId): void
    {
        $originalId = $this->documents->originalDocumentIdForSubDocument($subDocumentId);

        $this->extractAndSaveFieldsWithContext($subDocumentId, $this->documents->findOriginalDocument($originalId));
    }

    private function extractAndSaveFieldsWithContext(int $subDocumentId, OriginalDocumentRecord $original): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);

        try {
            $aiFields = $this->extractFields($subDocument, $original);
            // I metadati impostati in upload prevalgono sull'estrazione AI.
            $fields = $this->applyManualMetadataOverrides($aiFields, $original);
            $confidenceScore = $this->computeConfidenceScore($aiFields, $subDocument, $original);
            $reviewStatus = $this->reviewStatusForConfidence($confidenceScore);

            $this->documents->updateSubDocument($subDocumentId, [
                'review_status' => $reviewStatus,
                'error_message' => null,
            ]);
            $this->documents->saveExtractedData($subDocumentId, array_merge($fields, [
                'confidence_score' => $confidenceScore,
                'ai_payload' => $aiFields,
            ]));

            $this->metrics->recordDomainCounter('ai_extractions_total', ['review_status' => $reviewStatus->value]);
            $this->audit->record(
                $reviewStatus === ReviewStatus::AutoValidated ? 'mvp-sub-document-auto-validated' : 'mvp-sub-document-needs-review',
                resourceType: 'sub_document',
                resourceId: (string) $subDocumentId,
                metadata: [
                    'confidence_score' => $confidenceScore,
                    'confidence_threshold' => $this->confidenceThreshold(),
                    'review_status' => $reviewStatus->value,
                ],
                tenantId: $original->tenantId,
            );
        } catch (InvalidAiOutputException $e) {
            $safeMessage = 'Output AI non conforme: sotto-documento in quarantena.';
            Log::warning('ProcessDocumentService: AI output quarantined', [
                'sub_document_id' => $subDocumentId,
                'operation' => $e->operation(),
                'errors' => $e->errors(),
            ]);

            $this->documents->deleteExtractedData($subDocumentId);
            $this->documents->updateSubDocument($subDocumentId, [
                'review_status' => ReviewStatus::Quarantined,
                'error_message' => $safeMessage,
            ]);
            $this->audit->record(
                'mvp-sub-document-ai-output-quarantined',
                resourceType: 'sub_document',
                resourceId: (string) $subDocumentId,
                metadata: ['operation' => $e->operation(), 'errors' => $e->errors(), 'review_status' => ReviewStatus::Quarantined->value],
                tenantId: $original->tenantId,
            );
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $e->operation()]);
        } catch (\Throwable $e) {
            Log::error('ProcessDocumentService: extraction failed', ['sub_document_id' => $subDocumentId, 'message' => $e->getMessage()]);
            $this->documents->updateSubDocument($subDocumentId, [
                'error_message' => BedrockService::formatUserError($e, 'Estrazione campi non disponibile. Verifica configurazione e permessi Bedrock.'),
            ]);

            throw $e;
        }
    }

    private function handleProcessingFailure(int $documentId, \Throwable $e): void
    {
        Log::error('PDF Pipeline Failure', ['document_id' => $documentId, 'error' => $e->getMessage()]);

        $userMessage = $e instanceof InvalidAiOutputException
            ? 'Il classificatore AI ha restituito un output non valido: il documento non può essere elaborato automaticamente.'
            : BedrockService::formatUserError($e, 'Analisi documento non disponibile. Verifica configurazione e permessi Bedrock.');

        $this->documents->updateOriginalDocument($documentId, [
            'processing_status' => ProcessingStatus::Failed,
            'error_message' => $userMessage,
        ]);

        $tenantId = null;
        try {
            $tenantId = $this->documents->findOriginalDocument($documentId)->tenantId;
        } catch (\Throwable) {
            // Il documento potrebbe non essere piu' leggibile: l'audit resta comunque utile senza tenant.
        }

        $this->audit->record(
            'mvp-document-processing-failed',
            resourceType: 'original_document',
            resourceId: (string) $documentId,
            metadata: ['status' => ProcessingStatus::Failed->value, 'message' => $userMessage],
            tenantId: $tenantId,
        );

        if ($e instanceof InvalidAiOutputException) {
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $e->operation()]);
        }

        throw $e;
    }

    private function computeConfidenceScore(array $aiFields, SubDocumentRecord $subDocument, OriginalDocumentRecord $original): int
    {
        $keyFields = array_values(array_diff(
            ['employee_first_name', 'employee_last_name', 'company_name', 'document_date'],
            $this->manuallyDeclaredKeyFields($original),
        ));

        $ocrConfidence = $this->ocrConfidenceForRange($original, $subDocument->startPage, $subDocument->endPage);

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
     * @return list<string>
     */
    private function manuallyDeclaredKeyFields(OriginalDocumentRecord $original): array
    {
        $declared = [];

        if ($original->manualCompanyName !== null) {
            $declared[] = 'company_name';
        }

        if ($original->manualReferenceMonth !== null || $original->manualReferenceYear !== null) {
            $declared[] = 'document_date';
        }

        return $declared;
    }

    private function ocrConfidenceForRange(OriginalDocumentRecord $original, int $startPage, int $endPage): float
    {
        $pages = $original->ocrPages;

        if ($pages !== []) {
            $values = [];

            foreach ($pages as $page) {
                $number = (int) ($page['page'] ?? 0);

                if ($number < $startPage || $number > $endPage) {
                    continue;
                }

                if (isset($page['confidenceAvg']) && $page['confidenceAvg'] !== null) {
                    $values[] = (float) $page['confidenceAvg'];
                }
            }

            if ($values !== []) {
                return array_sum($values) / count($values);
            }
        }

        return (float) ($original->ocrConfidenceAvg ?? 0.0);
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
     * @param  array<int, array{employee_name?: string, start_page?: int, end_page?: int}>  $segments
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function normalizeSegments(array $segments, int $pageCount): array
    {
        // Garanzia di scope: un documento reale ha sempre almeno un destinatario.
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

    /**
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    private function splitDocument(OriginalDocumentRecord $original, int $pageCount): array
    {
        $boundaryNonce = $this->pageBoundaryNonce();
        $ocrText = $this->ocrTextForRange($original, 1, $pageCount, $boundaryNonce);

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile: Textract deve completare l\'estrazione prima dell\'analisi.');
        }

        return $this->ai->splitDocument($ocrText, $pageCount, $boundaryNonce);
    }

    /**
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function extractFields(SubDocumentRecord $subDocument, OriginalDocumentRecord $original): array
    {
        $ocrText = $this->ocrTextForRange($original, $subDocument->startPage, $subDocument->endPage, $this->pageBoundaryNonce());

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile per l\'intervallo di pagine del destinatario.');
        }

        return $this->ai->extractFields($ocrText);
    }

    /**
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}  $fields
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function applyManualMetadataOverrides(array $fields, OriginalDocumentRecord $original): array
    {
        if (! $original->hasManualUploadMetadata()) {
            return $fields;
        }

        if ($original->manualDocumentType !== null) {
            $fields['document_type'] = $original->manualDocumentType;
        }

        if ($original->manualCompanyName !== null) {
            $fields['company_name'] = $original->manualCompanyName;
        }

        $month = $original->manualReferenceMonth;
        $year = $original->manualReferenceYear;

        if ($month !== null && $year !== null) {
            $fields['document_date'] = sprintf('%04d-%02d-01', $year, $month);
        } elseif ($year !== null || $month !== null) {
            $fields['document_date'] = $this->mergeManualDateWithAi($fields['document_date'] ?? null, $month, $year);
        }

        return $fields;
    }

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

    private function pageBoundaryNonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function ocrTextForRange(OriginalDocumentRecord $original, int $startPage, int $endPage, string $boundaryNonce): string
    {
        $pages = $original->ocrPages;

        if ($pages !== []) {
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

        return trim((string) $original->ocrText);
    }

    private function deleteStoragePaths(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            try {
                $this->storage->delete($path);
            } catch (\Throwable $e) {
                Log::warning('ProcessDocumentService: storage cleanup failed', ['path' => $path, 'message' => $e->getMessage()]);
            }
        }
    }

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

            $this->storage->write($relativePath, (string) file_get_contents($absoluteDest));

            return $relativePath;
        } finally {
            @unlink($absoluteDest);
        }
    }

    private function copyStorageFileToTemporaryPath(string $storagePath): string
    {
        $contents = $this->storage->read($storagePath);
        $temporaryPath = $this->temporaryPath('source_');
        file_put_contents($temporaryPath, $contents);

        return $temporaryPath;
    }

    private function temporaryPath(string $prefix): string
    {
        $directory = storage_path('app/tmp/mvp-processing');

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $path = tempnam($directory, $prefix);

        if ($path === false) {
            throw new \RuntimeException('Impossibile creare un file temporaneo per il processamento PDF.');
        }

        return $path;
    }
}
