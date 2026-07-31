<?php

namespace App\Mvp\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Domain side of a Step Functions callback task.
 *
 * Claim, deduplication, heartbeat and callback handling live in
 * WorkflowTaskRunner: an implementation only resolves its own aggregate and
 * executes the business step.
 */
interface WorkflowTaskHandler
{
    /**
     * Task types routed to this handler.
     *
     * @return array<int, string>
     */
    public function taskTypes(): array;

    /**
     * Morph alias of the aggregate, also used as audit resource type.
     */
    public function subjectType(): string;

    /**
     * Audit event prefix, completed by the runner with the task status.
     */
    public function auditEventPrefix(): string;

    /**
     * @param  array<string, mixed>  $message
     */
    public function resolveSubject(array $message): Model;

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function execute(string $taskType, Model $subject, array $message): array;

    /**
     * Persist the failure on the aggregate before the runner rethrows.
     */
    public function onFailure(Model $subject, string $taskType, \Throwable $e): void;
}
