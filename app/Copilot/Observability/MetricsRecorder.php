<?php

namespace App\Copilot\Observability;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetricsRecorder
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->withMetricsFile(function (array $metrics): array {
            return $metrics;
        }, shared: true);
    }

    public function recordHttp(Request $request, Response $response, float $durationSeconds): void
    {
        if (! (bool) config('observability.metrics.enabled', true)) {
            return;
        }

        $routeName = $request->route()?->getName() ?: 'unmatched';

        if ($routeName === 'internal.metrics') {
            return;
        }

        $labels = [
            'method' => strtoupper($request->getMethod()),
            'route' => $this->normalizeLabelValue($routeName),
            'status' => (string) $response->getStatusCode(),
        ];

        $this->withMetricsFile(function (array $metrics) use ($labels, $durationSeconds): array {
            $metrics = $this->increment($metrics, 'http_requests_total', $labels);
            $metrics = $this->increment($metrics, 'http_request_duration_seconds_count', [
                'method' => $labels['method'],
                'route' => $labels['route'],
            ]);
            $metrics = $this->increment($metrics, 'http_request_duration_seconds_sum', [
                'method' => $labels['method'],
                'route' => $labels['route'],
            ], $durationSeconds);

            foreach ($this->durationBuckets() as $bucket) {
                if ($durationSeconds <= $bucket) {
                    $metrics = $this->increment($metrics, 'http_request_duration_seconds_bucket', [
                        'method' => $labels['method'],
                        'route' => $labels['route'],
                        'le' => $this->formatNumber($bucket),
                    ]);
                }
            }

            return $this->increment($metrics, 'http_request_duration_seconds_bucket', [
                'method' => $labels['method'],
                'route' => $labels['route'],
                'le' => '+Inf',
            ]);
        });
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function recordDomainCounter(string $name, array $labels = [], float $amount = 1.0): void
    {
        if (! (bool) config('observability.metrics.enabled', true)) {
            return;
        }

        $metric = preg_replace('/[^A-Za-z0-9_:]/', '_', $name) ?: 'domain_events_total';
        $normalizedLabels = [];

        foreach ($labels as $key => $value) {
            $normalizedKey = preg_replace('/[^A-Za-z0-9_]/', '_', $key) ?: 'label';
            $normalizedLabels[$normalizedKey] = $this->normalizeLabelValue((string) $value);
        }

        $this->withMetricsFile(function (array $metrics) use ($metric, $normalizedLabels, $amount): array {
            return $this->increment($metrics, $metric, $normalizedLabels, $amount);
        });
    }

    /**
     * @return array<int, float>
     */
    private function durationBuckets(): array
    {
        return array_map('floatval', config('observability.metrics.http_duration_buckets', [
            0.005,
            0.01,
            0.025,
            0.05,
            0.1,
            0.25,
            0.5,
            1,
            2.5,
            5,
            10,
        ]));
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private function increment(array $metrics, string $name, array $labels, float $amount = 1.0): array
    {
        ksort($labels);
        $key = hash('xxh128', json_encode($labels, JSON_THROW_ON_ERROR));
        $metrics[$name][$key] ??= [
            'labels' => $labels,
            'value' => 0,
        ];
        $metrics[$name][$key]['value'] += $amount;

        return $metrics;
    }

    /**
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $callback
     * @return T
     */
    private function withMetricsFile(callable $callback, bool $shared = false): mixed
    {
        $path = $this->metricsPath();

        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
            throw new \RuntimeException('Unable to create metrics storage directory.');
        }

        $handle = fopen($path, 'c+');

        if (! is_resource($handle)) {
            throw new \RuntimeException('Unable to open metrics storage file.');
        }

        try {
            flock($handle, $shared ? LOCK_SH : LOCK_EX);
            rewind($handle);
            $contents = stream_get_contents($handle);
            try {
                $metrics = is_string($contents) && trim($contents) !== ''
                    ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
                    : [];
            } catch (\JsonException) {
                $metrics = [];
            }

            if (! is_array($metrics)) {
                $metrics = [];
            }

            $result = $callback($metrics);

            if (! $shared) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function metricsPath(): string
    {
        return storage_path((string) config('observability.metrics.storage_path', 'app/private/observability/metrics.json'));
    }

    private function normalizeLabelValue(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?: 'unknown';

        return substr($value, 0, 120);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
