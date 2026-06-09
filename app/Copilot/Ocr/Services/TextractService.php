<?php

namespace App\Copilot\Ocr\Services;

use App\Copilot\Observability\MetricsRecorder;
use App\Models\Copilot\OriginalDocument;
use Aws\Exception\AwsException;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Log;

class TextractService
{
    public function __construct(
        private readonly TextractClient $client,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * @return array{enabled: bool, job_id: ?string, text: ?string, confidence_avg: ?float}
     */
    public function detectText(string $bucket, string $key, OriginalDocument $document): array
    {
        if (! (bool) config('services.textract.enabled')) {
            $this->metrics->recordDomainCounter('textract_jobs_skipped_total', ['reason' => 'disabled']);

            return [
                'enabled' => false,
                'job_id' => null,
                'text' => null,
                'confidence_avg' => null,
            ];
        }

        if ($bucket === '' || $key === '') {
            throw new \RuntimeException('Textract richiede bucket e key S3 reali.');
        }

        $startedAt = microtime(true);

        try {
            $result = $this->client->startDocumentTextDetection([
                'DocumentLocation' => [
                    'S3Object' => [
                        'Bucket' => $bucket,
                        'Name' => $key,
                    ],
                ],
                'ClientRequestToken' => 'poc-document-'.$document->id,
                'JobTag' => 'poc-document-'.$document->id,
            ]);

            $jobId = (string) $result->get('JobId');
            $document->update(['textract_job_id' => $jobId]);
            $this->metrics->recordDomainCounter('textract_jobs_started_total');

            $output = $this->pollTextDetection($jobId, $startedAt);
            $document->update([
                'ocr_text' => $output['text'],
                'ocr_confidence_avg' => $output['confidence_avg'],
            ]);
            $this->metrics->recordDomainCounter('textract_jobs_completed_total');
            $this->metrics->recordDomainCounter('textract_confidence_sum', [], (float) ($output['confidence_avg'] ?? 0));
            $this->metrics->recordDomainCounter('textract_confidence_count');
            $this->metrics->recordDomainCounter('textract_duration_seconds_sum', [], microtime(true) - $startedAt);
            $this->metrics->recordDomainCounter('textract_duration_seconds_count');

            return [
                'enabled' => true,
                'job_id' => $jobId,
                'text' => $output['text'],
                'confidence_avg' => $output['confidence_avg'],
            ];
        } catch (AwsException $e) {
            $this->metrics->recordDomainCounter('textract_jobs_failed_total', [
                'error' => $this->awsErrorCode($e),
            ]);
            Log::error('Textract OCR failed', [
                'document_id' => $document->id,
                'bucket' => $bucket,
                'key' => $key,
                'aws_error' => $this->awsErrorCode($e),
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ]);

            throw new \RuntimeException($this->userMessage($e), previous: $e);
        }
    }

    /**
     * @return array{text: string, confidence_avg: ?float}
     */
    private function pollTextDetection(string $jobId, float $startedAt): array
    {
        $timeout = max(1, (int) config('services.textract.timeout_seconds', 300));
        $interval = max(1, (int) config('services.textract.poll_interval_seconds', 5));
        $nextToken = null;
        $lines = [];
        $confidences = [];

        while (true) {
            if (microtime(true) - $startedAt > $timeout) {
                throw new \RuntimeException("Timeout Textract dopo {$timeout} secondi.");
            }

            $params = ['JobId' => $jobId];

            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }

            $result = $this->client->getDocumentTextDetection($params)->toArray();
            $status = (string) ($result['JobStatus'] ?? 'UNKNOWN');

            if ($status === 'FAILED') {
                throw new \RuntimeException((string) ($result['StatusMessage'] ?? 'Textract ha marcato il job come failed.'));
            }

            if ($status !== 'SUCCEEDED') {
                sleep($interval);

                continue;
            }

            foreach ($result['Blocks'] ?? [] as $block) {
                if (($block['BlockType'] ?? null) !== 'LINE') {
                    continue;
                }

                $lines[] = (string) ($block['Text'] ?? '');

                if (isset($block['Confidence'])) {
                    $confidences[] = (float) $block['Confidence'];
                }
            }

            $nextToken = $result['NextToken'] ?? null;

            if (! $nextToken) {
                break;
            }
        }

        return [
            'text' => trim(implode("\n", array_filter($lines))),
            'confidence_avg' => $confidences === [] ? null : array_sum($confidences) / count($confidences),
        ];
    }

    private function awsErrorCode(AwsException $e): string
    {
        return $e->getAwsErrorCode() ?: 'aws_error';
    }

    private function userMessage(AwsException $e): string
    {
        return match ($this->awsErrorCode($e)) {
            'AccessDeniedException', 'AccessDenied' => 'Textract non autorizzato: verifica IAM e bucket S3 reale.',
            'InvalidS3ObjectException' => 'Textract non riesce a leggere il documento da S3 reale.',
            'ThrottlingException', 'ProvisionedThroughputExceededException' => 'Textract è temporaneamente limitato per throttling.',
            'DocumentTooLargeException' => 'Documento troppo grande per Textract.',
            'UnsupportedDocumentException' => 'Formato documento non supportato da Textract.',
            default => 'Errore Textract: '.($e->getAwsErrorMessage() ?: $e->getMessage()),
        };
    }
}
