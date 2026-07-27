<?php

namespace App\Copilot\Workflow\Services;

use App\Copilot\Workflow\Contracts\WorkflowTaskHandler;

class WorkflowTaskRegistry
{
    /** @var array<string, WorkflowTaskHandler> */
    private array $handlers = [];

    public function register(WorkflowTaskHandler $handler): void
    {
        foreach ($handler->taskTypes() as $taskType) {
            $this->handlers[$taskType] = $handler;
        }
    }

    public function for(string $taskType): WorkflowTaskHandler
    {
        return $this->handlers[$taskType]
            ?? throw new \InvalidArgumentException("Task workflow non supportato: {$taskType}");
    }

    /**
     * @return array<int, string>
     */
    public function taskTypes(): array
    {
        return array_keys($this->handlers);
    }
}
