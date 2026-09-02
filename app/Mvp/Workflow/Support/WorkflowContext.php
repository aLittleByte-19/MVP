<?php

namespace App\Mvp\Workflow\Support;

/**
 * Correlation ids carried through a use case execution (HTTP request or SQS
 * task message). Puro contenitore di stato, nessuna dipendenza da Illuminate:
 * istanziabile con `new` in un test di dominio puro. Il tagging dei log
 * (`Log::withContext()`) vive negli adapter primari (`CorrelateRequests` per
 * l'HTTP, `ConsumeWorkflowTasks` per il worker SQS), non qui.
 */
class WorkflowContext
{
    private ?string $requestId = null;

    private ?string $correlationId = null;

    private ?string $tenantId = null;

    public function bind(?string $requestId, ?string $correlationId, ?string $tenantId = null): void
    {
        $this->requestId = $requestId !== '' ? $requestId : null;
        $this->correlationId = $correlationId !== '' ? $correlationId : null;
        $this->tenantId = $tenantId !== '' ? $tenantId : null;
    }

    public function clear(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
        $this->tenantId = null;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }
}
