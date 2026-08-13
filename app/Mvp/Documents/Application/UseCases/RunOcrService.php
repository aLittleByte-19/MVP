<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Events\DocumentProcessingFailed;
use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Documents\Enums\ProcessingStatus;

class RunOcrService implements RunOcrUseCase
{
    private const FAILURE_MESSAGE = 'OCR non disponibile. Verifica configurazione e permessi Textract.';

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly OcrGatewayPort $ocr,
        private readonly DocumentEventDispatcherPort $events,
    ) {}

    public function run(int $documentId, ?string $bucket, ?string $key): array
    {
        $document = $this->documents->findOriginalDocument($documentId);

        // Idempotenza verso la ridelivery del task workflow: un retry su un
        // documento gia' completato non deve rilanciare Textract (vedi
        // DocumentWorkflowTaskHandler).
        if ($document->processingStatus === ProcessingStatus::Completed->value) {
            return ['skipped' => true, 'jobId' => null, 'confidenceAvg' => null];
        }

        $resolvedBucket = $bucket ?: ($document->s3Bucket ?? '');
        $resolvedKey = $key ?: ($document->s3Key ?? '');

        // Il token di idempotenza dipende anche dall'oggetto S3, non solo
        // dall'id documento: dopo un reset/re-upload lo stesso id puo'
        // puntare a un file diverso, e un token fisso farebbe scattare
        // IdempotentParameterMismatchException contro il job precedente.
        $idempotencyKey = 'mvp-document-'.$documentId.'-'.substr(hash('sha256', $resolvedBucket.'/'.$resolvedKey), 0, 24);

        try {
            $result = $this->ocr->detectText($resolvedBucket, $resolvedKey, $idempotencyKey);
        } catch (\Throwable $e) {
            $this->documents->updateOriginalDocument($documentId, OriginalDocumentChanges::none()
                ->withProcessingStatus(ProcessingStatus::Failed)
                ->withErrorMessage(self::FAILURE_MESSAGE));
            $this->events->dispatch(new DocumentProcessingFailed($documentId, $document->tenantId, self::FAILURE_MESSAGE, false, null));

            throw $e;
        }

        if ($result['enabled']) {
            $this->documents->updateOriginalDocument($documentId, OriginalDocumentChanges::none()
                ->withTextractJobId($result['jobId'])
                ->withOcrText($result['text'])
                ->withOcrPages($result['pages'])
                ->withOcrConfidenceAvg($result['confidenceAvg']));
        }

        return [
            'skipped' => ! $result['enabled'],
            'jobId' => $result['jobId'],
            'confidenceAvg' => $result['confidenceAvg'],
        ];
    }
}
