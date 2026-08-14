<?php

namespace App\Mvp\Workflow\Support;

/**
 * Correlation ids carried through a use case execution (HTTP request or SQS
 * task message). Puro contenitore di stato, nessuna dipendenza da Illuminate:
 * istanziabile con `new` in un test di dominio puro. Il tagging dei log
 * (`Log::withContext()`) non vive più qui — per l'HTTP è già ridondante
 * (`App\Http\Middleware\CorrelateRequests` lo fa per ogni richiesta, stesse
 * chiavi `request_id`/`correlation_id`); per il worker SQS, che non ha quel
 * middleware, la stessa chiamata vive ora in `ConsumeWorkflowTasks` (adapter
 * primario, può legittimamente toccare la facade).
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
