<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Documents\Application\Support\OcrRangeReader;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentProcessingCompleted;
use App\Mvp\Documents\Domain\Events\DocumentProcessingFailed;
use App\Mvp\Documents\Domain\Events\DocumentProcessingStarted;
use App\Mvp\Documents\Domain\Ports\Inbound\ExtractSubDocumentFieldsUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\ValueObjects\NewSubDocument;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Fpdi;

/**
 * Applicazione: divide un documento originale nei suoi destinatari (Bedrock)
 * e avvia, per ciascuno, l'estrazione campi tramite
 * ExtractSubDocumentFieldsUseCase (prima la stessa logica viveva qui:
 * separata perche' l'estrazione per destinatario e' un'operazione a se'
 * stante, non solo un dettaglio interno dello split — vedi ADR 0010).
 * Sostituisce DocumentProcessingService::process(): stessa logica, ma
 * orchestrata attraverso le porte del dominio invece che Eloquent/Storage
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
        private readonly DocumentEventDispatcherPort $events,
        private readonly WorkflowTaskHeartbeat $heartbeat,
        private readonly LoggerInterface $logger,
        private readonly UniqueIdGeneratorPort $ids,
        private readonly OcrRangeReader $ocrRange,
        private readonly ExtractSubDocumentFieldsUseCase $fieldsExtractor,
    ) {}

    public function process(int $documentId): array
    {
        // Idempotenza verso la ridelivery del task workflow: un retry su un
        // documento gia' completato non deve rilanciare Bedrock ne'
        // ricreare i sotto-documenti (vedi DocumentWorkflowTaskHandler).
        if ($this->documents->findOriginalDocument($documentId)->processingStatus === ProcessingStatus::Completed->value) {
            return ['skipped' => true];
        }

        $absoluteSource = null;

        try {
            $this->documents->updateOriginalDocument($documentId, OriginalDocumentChanges::none()
                ->withProcessingStatus(ProcessingStatus::Processing)
                ->withErrorMessage(null));
            $original = $this->documents->findOriginalDocument($documentId);
            $this->events->dispatch(new DocumentProcessingStarted($documentId, $original->tenantId));

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
                    $subDocumentId = $this->documents->createSubDocument(new NewSubDocument(
                        originalDocumentId: $documentId,
                        filePath: $splitPath,
                        startPage: $segment['start_page'],
                        endPage: $segment['end_page'],
                    ));
                } catch (\Throwable $e) {
                    $this->deleteStoragePaths([$splitPath]);

                    throw $e;
                }

                $this->fieldsExtractor->extractAndSaveFieldsWithContext($subDocumentId, $original);
            }

            $this->documents->updateOriginalDocument($documentId, OriginalDocumentChanges::none()
                ->withProcessingStatus(ProcessingStatus::Completed)
                ->withErrorMessage(null));
            $this->events->dispatch(new DocumentProcessingCompleted($documentId, $original->tenantId));
        } catch (\Throwable $e) {
            $this->handleProcessingFailure($documentId, $e);
        } finally {
            if ($absoluteSource !== null) {
                @unlink($absoluteSource);
            }
        }

        return ['skipped' => false];
    }

    private function handleProcessingFailure(int $documentId, \Throwable $e): void
    {
        $this->logger->error('PDF Pipeline Failure', ['document_id' => $documentId, 'error' => $e->getMessage()]);

        $userMessage = $e instanceof InvalidAiOutputException
            ? 'Il classificatore AI ha restituito un output non valido: il documento non può essere elaborato automaticamente.'
            : $this->ai->formatUserError($e, 'Analisi documento non disponibile. Verifica configurazione e permessi Bedrock.');

        $this->documents->updateOriginalDocument($documentId, OriginalDocumentChanges::none()
            ->withProcessingStatus(ProcessingStatus::Failed)
            ->withErrorMessage($userMessage));

        $tenantId = null;
        try {
            $tenantId = $this->documents->findOriginalDocument($documentId)->tenantId;
        } catch (\Throwable) {
            // Il documento potrebbe non essere piu' leggibile: l'audit resta comunque utile senza tenant.
        }

        $this->events->dispatch(new DocumentProcessingFailed(
            $documentId,
            $tenantId,
            $userMessage,
            $e instanceof InvalidAiOutputException,
            $e instanceof InvalidAiOutputException ? $e->operation() : null,
        ));

        throw $e;
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
        $boundaryNonce = $this->ocrRange->nonce();
        $ocrText = $this->ocrRange->textForRange($original, 1, $pageCount, $boundaryNonce);

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile: Textract deve completare l\'estrazione prima dell\'analisi.');
        }

        return $this->ai->splitDocument($ocrText, $pageCount, $boundaryNonce);
    }

    private function deleteStoragePaths(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            try {
                $this->storage->delete($path);
            } catch (\Throwable $e) {
                $this->logger->warning('ProcessDocumentService: storage cleanup failed', ['path' => $path, 'message' => $e->getMessage()]);
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
            $relativePath = "sub/{$originalId}_{$slug}_{$startPage}-{$endPage}_".$this->ids->generate().'.pdf';

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
