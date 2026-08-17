<?php

namespace App\Mvp\Workflow\Contracts;

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
     * Pipeline this handler belongs to ('documents' or 'communications').
     *
     * The runner uses it to label failure metrics with the same state machine
     * reported by the use cases that start the workflow, so a single query can
     * cover both start failures and task failures.
     */
    public function pipeline(): string;

    /**
     * Audit event prefix, completed by the runner with the task status.
     */
    public function auditEventPrefix(): string;

    /**
     * @param  array<string, mixed>  $message
     */
    public function resolveSubject(array $message): WorkflowSubject;

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function execute(string $taskType, WorkflowSubject $subject, array $message): array;

    /**
     * Persist the failure on the aggregate before the runner rethrows.
     */
    public function onFailure(WorkflowSubject $subject, string $taskType, \Throwable $e): void;
}
