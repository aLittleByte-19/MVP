<?php

namespace App\Copilot\Workflow\Services;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Observability\MetricsRecorder;
use App\Models\Copilot\WorkflowTask;
use Illuminate\Support\Facades\Log;

/**
 * Domain-agnostic execution of a Step Functions callback task: it owns
 * deduplication, the atomic claim, audit and metrics, and delegates the
 * business step to the handler registered for the task type.
 */
class WorkflowTaskRunner
{
    public function __construct(
        private readonly WorkflowTaskRegistry $registry,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array{callback_required: bool, output: array<string, mixed>}
     */
    public function handle(array $message): array
    {
        $taskToken = (string) ($message['taskToken'] ?? $message['task_token'] ?? '');
        $taskType = (string) ($message['taskType'] ?? $message['task_type'] ?? '');

        if ($taskToken === '' || $taskType === '') {
            throw new \InvalidArgumentException('Messaggio workflow non valido: taskToken e taskType sono obbligatori.');
        }

        $handler = $this->registry->for($taskType);
        $subject = $handler->resolveSubject($message);
        $tokenHash = hash('sha256', $taskToken);

        $task = WorkflowTask::query()->firstOrCreate(
            ['task_token_hash' => $tokenHash],
            [
                'subject_type' => $handler->subjectType(),
                'subject_id' => $subject->getKey(),
                'task_type' => $taskType,
                'status' => 'pending',
                'input_payload' => $this->redactTaskToken($message),
            ],
        );

        if (in_array($task->status, ['succeeded', 'skipped'], true)) {
            return [
                'callback_required' => false,
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => $task->status,
                        'idempotent' => true,
                    ],
                ]),
            ];
        }

        if (! $this->claim($task)) {
            // Consegna duplicata mentre un altro worker sta gia' elaborando lo
            // stesso token: nessun callback, il worker attivo completera' il task.
            $this->metrics->recordDomainCounter('sqs_messages_duplicate_total', ['task_type' => $taskType]);

            return [
                'callback_required' => false,
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => 'running',
                        'duplicate_in_flight' => true,
                    ],
                ]),
            ];
        }
        $this->metrics->recordDomainCounter('sqs_messages_received_total', ['task_type' => $taskType]);

        try {
            $result = $handler->execute($taskType, $subject->refresh(), $message);
            $status = ($result['skipped'] ?? false) ? 'skipped' : 'succeeded';
            $output = array_merge($this->baseOutput($message), [
                'task_result' => array_merge($result, [
                    'task_type' => $taskType,
                    'status' => $status,
                ]),
            ]);

            $task->update([
                'status' => $status,
                'output_payload' => $output['task_result'],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $handler->auditEventPrefix().$status,
                resourceType: $handler->subjectType(),
                resourceId: (string) $subject->getKey(),
                metadata: ['task_type' => $taskType],
                tenantId: $subject->getAttribute('tenant_id'),
            );

            return ['callback_required' => true, 'output' => $output];
        } catch (\Throwable $e) {
            $task->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            $handler->onFailure($subject->refresh(), $taskType, $e);

            $this->metrics->recordDomainCounter('sqs_messages_failed_total', ['task_type' => $taskType]);
            $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', ['reason' => 'task_failure']);
            Log::error('Workflow task failed', [
                'subject_type' => $handler->subjectType(),
                'subject_id' => $subject->getKey(),
                'task_type' => $taskType,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Claim atomico del task: con piu' worker solo uno puo' portare lo stato a
     * running. Un task gia' running viene riconquistato solo se stale (worker
     * morto oltre il visibility timeout SQS).
     */
    private function claim(WorkflowTask $task): bool
    {
        $staleBefore = now()->subSeconds(max(60, (int) config('mvp.workflow.running_claim_ttl_seconds', 900)));

        $claimed = WorkflowTask::query()
            ->whereKey($task->id)
            ->where(function ($query) use ($staleBefore) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($running) use ($staleBefore) {
                        $running->where('status', 'running')
                            ->where(function ($stale) use ($staleBefore) {
                                $stale->whereNull('started_at')->orWhere('started_at', '<', $staleBefore);
                            });
                    });
            })
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'error_message' => null,
            ]);

        if ($claimed === 1) {
            $task->refresh();

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function baseOutput(array $message): array
    {
        return collect($message)
            ->except(['taskToken', 'task_token'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function redactTaskToken(array $message): array
    {
        return array_merge($this->baseOutput($message), [
            'task_token_present' => isset($message['taskToken']) || isset($message['task_token']),
        ]);
    }
}
