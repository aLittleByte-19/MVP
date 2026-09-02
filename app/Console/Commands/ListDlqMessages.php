<?php

namespace App\Console\Commands;

use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;

/**
 * Strumento di diagnosi manuale sulla profondita' e il contenuto di una DLQ:
 * interroga SQS per conto proprio, senza dipendere da alcuna infrastruttura
 * di monitoraggio.
 */
class ListDlqMessages extends Command
{
    protected $signature = 'mvp:dlq:list {--queue=documents : Pipeline DLQ to inspect: documents or communications} {--limit=10 : Maximum DLQ messages to inspect}';

    protected $description = 'List workflow DLQ messages without deleting them.';

    public function handle(SqsClient $sqs): int
    {
        $pipeline = (string) $this->option('queue');
        $queueUrl = match ($pipeline) {
            'documents' => (string) config('services.workflow.dlq_queue_url'),
            'communications' => (string) config('services.workflow.communications_dlq_queue_url'),
            default => null,
        };

        if ($queueUrl === null) {
            $this->error("Pipeline workflow non supportata: {$pipeline}. Valori ammessi: documents, communications.");

            return self::FAILURE;
        }

        if ($queueUrl === '') {
            $this->error("DLQ della pipeline '{$pipeline}' non configurata: esegui 'make refresh-runtime' per rileggere i parametri runtime.");

            return self::FAILURE;
        }

        $limit = min(10, max(1, (int) $this->option('limit')));
        $result = $sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'MaxNumberOfMessages' => $limit,
            'WaitTimeSeconds' => 0,
            'AttributeNames' => ['All'],
            'MessageAttributeNames' => ['All'],
            'VisibilityTimeout' => 0,
        ]);
        $messages = $result->get('Messages') ?? [];

        if ($messages === []) {
            $this->info('DLQ vuota.');

            return self::SUCCESS;
        }

        $this->table(
            ['message_id', 'sent_at', 'receive_count', 'body_preview'],
            collect($messages)->map(fn (array $message): array => [
                $message['MessageId'] ?? '',
                $message['Attributes']['SentTimestamp'] ?? '',
                $message['Attributes']['ApproximateReceiveCount'] ?? '',
                substr((string) ($message['Body'] ?? ''), 0, 120),
            ])->all()
        );

        return self::SUCCESS;
    }
}
