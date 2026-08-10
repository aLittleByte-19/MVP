<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;

class RunOcrService implements RunOcrUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly OcrGatewayPort $ocr,
    ) {}

    public function run(int $documentId, ?string $bucket, ?string $key): array
    {
        $document = $this->documents->findOriginalDocument($documentId);
        $resolvedBucket = $bucket ?: ($document->s3Bucket ?? '');
        $resolvedKey = $key ?: ($document->s3Key ?? '');

        // Il token di idempotenza dipende anche dall'oggetto S3, non solo
        // dall'id documento: dopo un reset/re-upload lo stesso id puo'
        // puntare a un file diverso, e un token fisso farebbe scattare
        // IdempotentParameterMismatchException contro il job precedente.
        $idempotencyKey = 'mvp-document-'.$documentId.'-'.substr(hash('sha256', $resolvedBucket.'/'.$resolvedKey), 0, 24);

        $result = $this->ocr->detectText($resolvedBucket, $resolvedKey, $idempotencyKey);

        if ($result['enabled']) {
            $this->documents->updateOriginalDocument($documentId, [
                'textract_job_id' => $result['jobId'],
                'ocr_text' => $result['text'],
                'ocr_pages' => $result['pages'],
                'ocr_confidence_avg' => $result['confidenceAvg'],
            ]);
        }

        return [
            'skipped' => ! $result['enabled'],
            'jobId' => $result['jobId'],
            'confidenceAvg' => $result['confidenceAvg'],
        ];
    }
}
