<?php

namespace App\Copilot\Workflow\Support;

use Illuminate\Support\Facades\Log;

/**
 * Correlation ids carried by an SQS task message.
 *
 * The worker has no HTTP request to read them from: binding them here keeps
 * log context and audit events attached to the request that started the
 * execution.
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

        Log::withContext(array_filter([
            'request_id' => $this->requestId,
            'correlation_id' => $this->correlationId,
        ]));
    }

    public function clear(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
        $this->tenantId = null;

        Log::withoutContext();
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
