<?php

namespace App\Http\Middleware;

use App\Copilot\Observability\MetricsRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordHttpMetrics
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $response = null;

        try {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        } finally {
            $durationSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            try {
                $this->metrics->recordHttp($request, $response ?? new Response(status: 500), $durationSeconds);
            } catch (\Throwable) {
                // Metrics collection is intentionally fail-open.
            }
        }
    }
}
