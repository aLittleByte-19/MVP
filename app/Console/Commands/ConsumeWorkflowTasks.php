<?php

namespace App\Console\Commands;

use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Workflow\Services\WorkflowTaskHeartbeat;
use App\Copilot\Workflow\Services\WorkflowTaskRunner;
use App\Copilot\Workflow\Support\WorkflowContext;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;

class ConsumeWorkflowTasks extends Command
{
    protected $signature = 'mvp:workflow:consume {--queue=documents : Pipeline queue to consume: documents or communications} {--once : Stop after one polling cycle} {--max=0 : Maximum messages before exit; 0 means unlimited} {--wait= : Long polling wait seconds}';

    protected $description = 'Consume Step Functions callback-token tasks from SQS and report completion back to Step Functions.';

    public function handle(SqsClient $sqs, SfnClient $stepFunctions, WorkflowTaskRunner $runner, WorkflowTaskHeartbeat $heartbeat, WorkflowContext $context, MetricsRecorder $metrics): int
    {
        $pipeline = (string) $this->option('queue');
        $maxMessages = max(0, (int) $this->option('max'));
        $waitSeconds = $this->option('wait') !== null
            ? max(0, (int) $this->option('wait'))
            : max(0, (int) config('mvp.workflow.poll_wait_seconds', 10));
        $processed = 0;

        try {
            $queueUrl = $this->queueUrl($pipeline);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($queueUrl === '') {
            $this->error("Coda della pipeline '{$pipeline}' non configurata: esegui 'make refresh-runtime' per rileggere i parametri runtime.");

            return self::FAILURE;
        }

        do {
            $result = $sqs->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => min(10, max(1, (int) config('mvp.workflow.max_messages', 5))),
                'WaitTimeSeconds' => $waitSeconds,
                'MessageAttributeNames' => ['All'],
            ]);

            foreach ($result->get('Messages') ?? [] as $message) {
                $processed++;
                $receiptHandle = (string) ($message['ReceiptHandle'] ?? '');
                $body = $this->decodeBody((string) ($message['Body'] ?? ''));
                $taskToken = (string) ($body['taskToken'] ?? $body['task_token'] ?? '');

                if ($taskToken !== '') {
                    $heartbeat->activate($taskToken, (string) ($body['taskType'] ?? $body['task_type'] ?? 'unknown'));
                }

                // Il worker non ha una request HTTP: gli id di correlazione
                // viaggiano nel messaggio e vanno riagganciati a log e audit.
                $context->bind(
                    isset($body['requestId']) ? (string) $body['requestId'] : (isset($body['request_id']) ? (string) $body['request_id'] : null),
                    isset($body['correlationId']) ? (string) $body['correlationId'] : (isset($body['correlation_id']) ? (string) $body['correlation_id'] : null),
                    isset($body['tenantId']) ? (string) $body['tenantId'] : (isset($body['tenant_id']) ? (string) $body['tenant_id'] : null),
                );

                try {
                    $taskResult = $runner->handle($body);

                    if ($taskResult['callback_required']) {
                        $this->sendCallback($metrics, fn () => $stepFunctions->sendTaskSuccess([
                            'taskToken' => $taskToken,
                            'output' => json_encode($taskResult['output'], JSON_THROW_ON_ERROR),
                        ]), 'sendTaskSuccess');
                    }

                    $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                    $this->info('Workflow task handled: '.($body['taskType'] ?? $body['task_type'] ?? 'unknown'));
                } catch (\Throwable $e) {
                    if ($taskToken !== '') {
                        $this->sendCallback($metrics, fn () => $stepFunctions->sendTaskFailure([
                            'taskToken' => $taskToken,
                            'error' => 'WorkflowTaskFailed',
                            'cause' => substr($e->getMessage(), 0, 32000),
                        ]), 'sendTaskFailure');
                        $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                    }

                    $this->error($e->getMessage());
                } finally {
                    $heartbeat->deactivate();
                    $context->clear();
                }

                if ($maxMessages > 0 && $processed >= $maxMessages) {
                    return self::SUCCESS;
                }
            }

            if ($this->option('once')) {
                break;
            }
        } while (true);

        return self::SUCCESS;
    }

    /**
     * Un callback rifiutato (token gia' consumato, esecuzione scaduta per
     * heartbeat/timeout, emulatore non allineato ad AWS) non deve abbattere
     * il loop di consumo: l'esito di business resta tracciato a database.
     */
    private function sendCallback(MetricsRecorder $metrics, callable $callback, string $operation): void
    {
        try {
            $callback();
        } catch (AwsException $e) {
            $metrics->recordDomainCounter('stepfunctions_callbacks_failed_total', [
                'operation' => $operation,
                'error' => $e->getAwsErrorCode() ?: 'aws_error',
            ]);
            $this->warn("{$operation} rifiutato da Step Functions: ".($e->getAwsErrorMessage() ?: $e->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(string $body): array
    {
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        }

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('SQS message body is not a JSON object.');
        }

        return $decoded;
    }

    private function deleteMessage(SqsClient $sqs, string $queueUrl, string $receiptHandle): void
    {
        if ($receiptHandle === '') {
            return;
        }

        $sqs->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    private function queueUrl(string $pipeline): string
    {
        $configured = match ($pipeline) {
            'documents' => (string) config('services.workflow.task_queue_url'),
            'communications' => (string) config('services.workflow.communications_task_queue_url'),
            default => throw new \InvalidArgumentException("Pipeline workflow non supportata: {$pipeline}. Valori ammessi: documents, communications."),
        };

        if ($configured !== '' || $pipeline !== 'documents') {
            return $configured;
        }

        $prefix = rtrim((string) config('queue.connections.sqs.prefix'), '/');
        $queue = (string) config('queue.connections.sqs.queue');

        return $prefix !== '' && $queue !== '' ? "{$prefix}/{$queue}" : '';
    }
}
