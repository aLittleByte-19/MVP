<?php

namespace App\Mvp\Workflow\Services;

use App\Models\WorkflowTask;
use App\Mvp\Audit\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Domain-agnostic execution of a Step Functions callback task: it owns
 * deduplication, the atomic claim, audit, and delegates the business step
 * to the handler registered for the task type.
 */
class WorkflowTaskRunner
{
    public function __construct(
        private readonly WorkflowTaskRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array{callback_required: bool, callback?: 'success'|'failure', duplicate_in_flight?: bool, output: array<string, mixed>, error?: string}
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

        try {
            $task = WorkflowTask::query()->firstOrCreate(
                ['task_token_hash' => $tokenHash],
                [
                    'subject_type' => $handler->subjectType(),
                    'subject_id' => $subject->id,
                    'task_type' => $taskType,
                    'status' => 'pending',
                    'input_payload' => $this->redactTaskToken($message),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $task = WorkflowTask::query()->where('task_token_hash', $tokenHash)->firstOrFail();
        }

        if (in_array($task->status, ['succeeded', 'skipped'], true)) {
            return [
                'callback_required' => true,
                'callback' => 'success',
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => $task->status,
                        'idempotent' => true,
                    ],
                ]),
            ];
        }

        if ($task->status === 'failed') {
            return [
                'callback_required' => true,
                'callback' => 'failure',
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => 'failed',
                        'idempotent' => true,
                    ],
                ]),
                'error' => (string) ($task->error_message ?: 'Workflow task failed'),
            ];
        }

        if (! $this->claim($task)) {
            // Consegna duplicata mentre un altro worker sta gia' elaborando lo
            // stesso token: nessun callback, il worker attivo completera' il task.
            return [
                'callback_required' => false,
                'duplicate_in_flight' => true,
                'output' => array_merge($this->baseOutput($message), [
                    'task_result' => [
                        'task_type' => $taskType,
                        'status' => 'running',
                        'duplicate_in_flight' => true,
                    ],
                ]),
            ];
        }

        try {
            // Ri-risolve il subject invece di un semplice refresh: tra il
            // claim e l'esecuzione puo' essere passato tempo (worker
            // riconquistato dopo uno stale claim), e il subject serve
            // comunque aggiornato. Stesso numero di letture DB di prima,
            // solo attraverso il repository di dominio invece di Eloquent.
            $subject = $handler->resolveSubject($message);
            $result = $handler->execute($taskType, $subject, $message);
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
                resourceId: (string) $subject->id,
                metadata: ['task_type' => $taskType],
                tenantId: $subject->tenantId,
            );

            return ['callback_required' => true, 'callback' => 'success', 'output' => $output];
        } catch (\Throwable $e) {
            $task->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            $subject = $handler->resolveSubject($message);
            $handler->onFailure($subject, $taskType, $e);

            Log::error('Workflow task failed', [
                'subject_type' => $handler->subjectType(),
                'subject_id' => $subject->id,
                'task_type' => $taskType,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Claim atomico del task: con piu' worker solo uno puo' portare lo stato a
     * running. Un task gia' running viene riconquistato solo se stale (worker
     * morto oltre il visibility timeout SQS). Un task failed non viene
     * rieseguito: il consumer ritenta solo SendTaskFailure.
     */
    private function claim(WorkflowTask $task): bool
    {
        $staleBefore = now()->subSeconds(max(60, (int) config('mvp.workflow.running_claim_ttl_seconds', 900)));

        $claimed = WorkflowTask::query()
            ->whereKey($task->id)
            ->where(function ($query) use ($staleBefore) {
                $query->where('status', 'pending')
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
