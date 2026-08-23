<?php

namespace App\Console\Commands;

use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use App\Mvp\Workflow\Services\WorkflowTaskRunner;
use App\Mvp\Workflow\Support\WorkflowContext;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ConsumeWorkflowTasks extends Command
{
    protected $signature = 'mvp:workflow:consume {--queue=documents : Pipeline queue to consume: documents or communications} {--once : Stop after one polling cycle} {--max=0 : Maximum messages before exit; 0 means unlimited} {--wait= : Long polling wait seconds}';

    protected $description = 'Consume Step Functions callback-token tasks from SQS and report completion back to Step Functions.';

    /**
     * Codici di errore Step Functions per cui un retry con lo stesso token
     * non puo' mai riuscire (token gia' consumato, scaduto per heartbeat, o
     * malformato). Il lavoro di business e' gia' tracciato a database:
     * lasciare il messaggio in coda produrrebbe solo retry inutili fino alla
     * DLQ, popolandola di falsi fallimenti per un task gia' concluso.
     *
     * @var list<string>
     */
    private const PERMANENT_CALLBACK_ERRORS = ['TaskTimedOut', 'TaskDoesNotExist', 'InvalidToken'];

    public function handle(SqsClient $sqs, SfnClient $stepFunctions, WorkflowTaskRunner $runner, WorkflowTaskHeartbeat $heartbeat, WorkflowContext $context): int
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
                // Il tagging dei log e' qui (adapter), non dentro WorkflowContext
                // (classe pura, vedi il suo docblock) — stesso posto in cui
                // App\Http\Middleware\CorrelateRequests lo fa per l'HTTP.
                $context->bind(
                    isset($body['requestId']) ? (string) $body['requestId'] : (isset($body['request_id']) ? (string) $body['request_id'] : null),
                    isset($body['correlationId']) ? (string) $body['correlationId'] : (isset($body['correlation_id']) ? (string) $body['correlation_id'] : null),
                    isset($body['tenantId']) ? (string) $body['tenantId'] : (isset($body['tenant_id']) ? (string) $body['tenant_id'] : null),
                );

                Log::withContext(array_filter([
                    'request_id' => $context->requestId(),
                    'correlation_id' => $context->correlationId(),
                ]));

                try {
                    $taskResult = $runner->handle($body);

                    if ($taskResult['duplicate_in_flight'] ?? false) {
                        $this->info('Workflow task already in flight; message left on queue.');

                        continue;
                    }

                    $callbackOk = true;

                    if ($taskResult['callback_required']) {
                        if (($taskResult['callback'] ?? 'success') === 'failure') {
                            $callbackOk = $this->sendCallback(fn () => $stepFunctions->sendTaskFailure([
                                'taskToken' => $taskToken,
                                'error' => 'WorkflowTaskFailed',
                                'cause' => substr((string) ($taskResult['error'] ?? 'Workflow task failed'), 0, 32000),
                            ]), 'sendTaskFailure');
                        } else {
                            $callbackOk = $this->sendCallback(fn () => $stepFunctions->sendTaskSuccess([
                                'taskToken' => $taskToken,
                                'output' => json_encode($taskResult['output'], JSON_THROW_ON_ERROR),
                            ]), 'sendTaskSuccess');
                        }
                    }

                    if ($callbackOk) {
                        $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                        $this->info('Workflow task handled: '.($body['taskType'] ?? $body['task_type'] ?? 'unknown'));
                    } else {
                        $this->warn('Callback Step Functions non confermato: il messaggio resta in coda.');
                    }
                } catch (\Throwable $e) {
                    $callbackOk = false;

                    if ($taskToken !== '') {
                        $callbackOk = $this->sendCallback(fn () => $stepFunctions->sendTaskFailure([
                            'taskToken' => $taskToken,
                            'error' => 'WorkflowTaskFailed',
                            'cause' => substr($e->getMessage(), 0, 32000),
                        ]), 'sendTaskFailure');
                    }

                    if ($callbackOk) {
                        $this->deleteMessage($sqs, $queueUrl, $receiptHandle);
                    }

                    $this->error($e->getMessage());
                } finally {
                    $heartbeat->deactivate();
                    $context->clear();
                    Log::withoutContext();
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
     * Un callback rifiutato da un errore transitorio (throttling, servizio
     * non disponibile, emulatore non allineato ad AWS) non deve abbattere il
     * loop di consumo: l'esito di business resta tracciato a database, ma il
     * messaggio SQS resta in coda finche' Step Functions non accetta il
     * callback (un token nuovo arrivera' al retry ASL). Un errore permanente
     * (vedi PERMANENT_CALLBACK_ERRORS) e' invece trattato come successo ai
     * fini della coda: nessun retry con lo stesso token puo' riuscire.
     */
    private function sendCallback(callable $callback, string $operation): bool
    {
        try {
            $callback();

            return true;
        } catch (AwsException $e) {
            $errorCode = $e->getAwsErrorCode() ?: 'aws_error';
            $this->warn("{$operation} rifiutato da Step Functions: ".($e->getAwsErrorMessage() ?: $e->getMessage()));

            if (in_array($errorCode, self::PERMANENT_CALLBACK_ERRORS, true)) {
                $this->warn("Errore permanente ({$errorCode}): il messaggio viene rimosso dalla coda, un retry con lo stesso token non potrebbe riuscire.");

                return true;
            }

            return false;
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
