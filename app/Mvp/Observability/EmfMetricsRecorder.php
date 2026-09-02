<?php

namespace App\Mvp\Observability;

use Illuminate\Support\Facades\Log;

/**
 * Emette metriche custom in CloudWatch Embedded Metric Format (EMF): una riga
 * JSON sul canale di log "metrics", con un blocco "_aws.CloudWatchMetrics"
 * che CloudWatch interpreta automaticamente come dato di metrica quando la
 * riga arriva da CloudWatch Logs. Nessun endpoint di scrape, nessun collector,
 * nessuna chiamata SDK PutMetricData — vedi ADR 0015.
 */
class EmfMetricsRecorder
{
    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $namespace = 'MVP/App',
    ) {}

    /**
     * @param  array<string, string>  $dimensions  es. ['Route' => 'api.communications.store']
     * @param  array<string, array{value: float|int, unit: string}>  $metrics  es. ['Latency' => ['value' => 12.3, 'unit' => 'Milliseconds']]
     * @param  array<string, scalar|null>  $properties  campi extra non indicizzati (es. request_id) per correlazione in Logs Insights
     */
    public function put(array $dimensions, array $metrics, array $properties = []): void
    {
        if (! $this->enabled || $metrics === []) {
            return;
        }

        $payload = [
            '_aws' => [
                'Timestamp' => (int) round(microtime(true) * 1000),
                'CloudWatchMetrics' => [[
                    'Namespace' => $this->namespace,
                    'Dimensions' => [array_keys($dimensions)],
                    'Metrics' => array_map(
                        fn (string $name, array $definition): array => ['Name' => $name, 'Unit' => $definition['unit']],
                        array_keys($metrics),
                        $metrics,
                    ),
                ]],
            ],
        ];

        foreach ($dimensions as $key => $value) {
            $payload[$key] = $value;
        }

        foreach ($metrics as $name => $definition) {
            $payload[$name] = $definition['value'];
        }

        foreach ($properties as $key => $value) {
            $payload[$key] = $value;
        }

        Log::channel('metrics')->info(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
