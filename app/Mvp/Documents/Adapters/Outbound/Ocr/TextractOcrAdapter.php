<?php

namespace App\Mvp\Documents\Adapters\Outbound\Ocr;

use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\Exception\AwsException;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Log;

/**
 * Adapter secondario: implementa {@see OcrGatewayPort} sopra Amazon Textract.
 * Non tocca la persistenza dell'aggregato: restituisce il risultato grezzo,
 * chi orchestra il caso d'uso decide cosa salvarne e dove.
 */
class TextractOcrAdapter implements OcrGatewayPort
{
    public function __construct(
        private readonly TextractClient $client,
        private readonly WorkflowTaskHeartbeat $heartbeat,
    ) {}

    public function detectText(string $bucket, string $key, string $idempotencyKey): array
    {
        if (! (bool) config('services.textract.enabled')) {
            return [
                'enabled' => false,
                'jobId' => null,
                'text' => null,
                'pages' => [],
                'confidenceAvg' => null,
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
                'ClientRequestToken' => $idempotencyKey,
                'JobTag' => $idempotencyKey,
            ]);

            $jobId = (string) $result->get('JobId');
            $output = $this->pollTextDetection($jobId, $startedAt);

            return [
                'enabled' => true,
                'jobId' => $jobId,
                'text' => $output['text'],
                'pages' => $output['pages'],
                'confidenceAvg' => $output['confidenceAvg'],
            ];
        } catch (AwsException $e) {
            Log::error('Textract OCR failed', [
                'bucket' => $bucket,
                'key' => $key,
                'aws_error' => $this->awsErrorCode($e),
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ]);

            throw new \RuntimeException($this->userMessage($e), previous: $e);
        }
    }

    /**
     * @return array{text: string, pages: array<int, array{page: int, text: string, confidenceAvg: float|null, blocks: array<int, array{text: string, confidence: float|null}>}>, confidenceAvg: ?float}
     */
    private function pollTextDetection(string $jobId, float $startedAt): array
    {
        $timeout = max(1, (int) config('services.textract.timeout_seconds', 300));
        $interval = max(1, (int) config('services.textract.poll_interval_seconds', 5));
        $nextToken = null;
        $lines = [];
        $confidences = [];

        /** @var array<int, array{lines: array<int, string>, confidences: array<int, float>, blocks: array<int, array{text: string, confidence: float|null}>}> $pageBuckets */
        $pageBuckets = [];

        while (true) {
            $this->heartbeat->beat();

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

                $text = (string) ($block['Text'] ?? '');
                $page = max(1, (int) ($block['Page'] ?? 1));
                $confidence = isset($block['Confidence']) ? (float) $block['Confidence'] : null;

                $lines[] = $text;
                $pageBuckets[$page]['lines'][] = $text;

                // La confidenza della singola riga viene conservata, non solo
                // sommata nella media: e' cio' che permette di attribuire a
                // ciascun campo estratto la leggibilita' del testo da cui
                // proviene, invece della leggibilita' media della pagina
                // (ADR 0013).
                $pageBuckets[$page]['blocks'][] = [
                    'text' => $text,
                    'confidence' => $confidence,
                ];

                if ($confidence !== null) {
                    $confidences[] = $confidence;
                    $pageBuckets[$page]['confidences'][] = $confidence;
                }
            }

            $nextToken = $result['NextToken'] ?? null;

            if (! $nextToken) {
                break;
            }
        }

        ksort($pageBuckets);
        $pages = [];

        foreach ($pageBuckets as $page => $bucket) {
            $pageConfidences = $bucket['confidences'] ?? [];
            $pages[] = [
                'page' => $page,
                'text' => trim(implode("\n", array_filter($bucket['lines'] ?? []))),
                'confidenceAvg' => $pageConfidences === [] ? null : array_sum($pageConfidences) / count($pageConfidences),
                'blocks' => $bucket['blocks'] ?? [],
            ];
        }

        return [
            'text' => trim(implode("\n", array_filter($lines))),
            'pages' => $pages,
            'confidenceAvg' => $confidences === [] ? null : array_sum($confidences) / count($confidences),
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
