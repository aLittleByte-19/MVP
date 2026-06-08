<?php

namespace App\Poc\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelateRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->normalizeHeader($request->headers->get('X-Request-ID')) ?: (string) Str::uuid();
        $correlationId = $this->normalizeHeader($request->headers->get('X-Correlation-ID')) ?: $requestId;

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);

        Log::withContext([
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }

    private function normalizeHeader(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return preg_replace('/[^A-Za-z0-9_.:-]/', '', $value) ?: null;
    }
}
