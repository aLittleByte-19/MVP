<?php

namespace App\Console\Commands;

use App\Mvp\Observability\EmfMetricsRecorder;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;

/**
 * Pubblica la profondita' delle DLQ documents/communications come metrica
 * EMF. Non e' collegata a uno scheduler (questo repo non ne ha uno, vedi ADR
 * 0015): va invocata a mano o da un job scheduler esterno se l'ambiente di
 * destinazione lo prevede — coerente con l'assenza di un ambiente AWS reale
 * provisionato per questo progetto (infra/aws e' solo un placeholder).
 */
class PublishDlqDepthMetric extends Command
{
    protected $signature = 'mvp:metrics:dlq-depth';

    protected $description = 'Emette la profondita delle DLQ documents/communications come metrica EMF.';

    public function handle(SqsClient $sqs, EmfMetricsRecorder $metrics): int
    {
        $queues = [
            'documents' => (string) config('services.workflow.dlq_queue_url'),
            'communications' => (string) config('services.workflow.communications_dlq_queue_url'),
        ];

        foreach ($queues as $pipeline => $queueUrl) {
            if ($queueUrl === '') {
                continue;
            }

            $result = $sqs->getQueueAttributes([
                'QueueUrl' => $queueUrl,
                'AttributeNames' => ['ApproximateNumberOfMessages'],
            ]);

            $metrics->put(
                dimensions: ['Pipeline' => $pipeline],
                metrics: ['DlqDepth' => [
                    'value' => (int) ($result->get('Attributes')['ApproximateNumberOfMessages'] ?? 0),
                    'unit' => 'Count',
                ]],
            );
        }

        return self::SUCCESS;
    }
}
