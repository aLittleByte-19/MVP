<?php

namespace App\Console\Commands;

use App\Copilot\Observability\MetricsRecorder;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;

class ListDlqMessages extends Command
{
    protected $signature = 'mvp:dlq:list {--limit=10 : Maximum DLQ messages to inspect}';

    protected $description = 'List document workflow DLQ messages without deleting them.';

    public function handle(SqsClient $sqs, MetricsRecorder $metrics): int
    {
        $queueUrl = (string) config('services.sqs.dlq_queue_url');

        if ($queueUrl === '') {
            $this->error('SQS_DLQ_URL non configurata.');

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
        $metrics->recordDomainCounter('dlq_messages_total', ['queue' => 'documents'], count($messages));

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
