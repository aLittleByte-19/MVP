<?php

namespace App\Copilot\Observability;

use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Models\Copilot\Communication;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
use Illuminate\Support\Facades\DB;

class PrometheusExporter
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function render(): string
    {
        $lines = [
            '# HELP poc_app_info Static application metadata.',
            '# TYPE poc_app_info gauge',
            $this->line('poc_app_info', [
                'service_name' => (string) config('observability.service.name'),
                'service_namespace' => (string) config('observability.service.namespace'),
                'service_version' => (string) config('observability.service.version'),
                'deployment_environment' => (string) config('observability.service.environment'),
            ], 1),
            '# HELP poc_readiness_status Readiness check status by dependency.',
            '# TYPE poc_readiness_status gauge',
        ];

        foreach ($this->readinessChecks() as $check => $ready) {
            $lines[] = $this->line('poc_readiness_status', ['check' => $check], $ready ? 1 : 0);
        }

        $lines = array_merge($lines, $this->httpMetrics(), $this->domainMetrics(), $this->recordedDomainMetrics());

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<int, string>
     */
    private function httpMetrics(): array
    {
        $snapshot = $this->metrics->snapshot();
        $lines = [
            '# HELP poc_http_requests_total Total HTTP requests handled by the application.',
            '# TYPE poc_http_requests_total counter',
        ];

        foreach ($this->samples($snapshot, 'http_requests_total') as $sample) {
            $lines[] = $this->line('poc_http_requests_total', $sample['labels'], (float) $sample['value']);
        }

        $lines[] = '# HELP poc_http_request_duration_seconds HTTP request duration histogram.';
        $lines[] = '# TYPE poc_http_request_duration_seconds histogram';

        foreach ($this->samples($snapshot, 'http_request_duration_seconds_bucket') as $sample) {
            $lines[] = $this->line('poc_http_request_duration_seconds_bucket', $sample['labels'], (float) $sample['value']);
        }

        foreach ($this->samples($snapshot, 'http_request_duration_seconds_sum') as $sample) {
            $lines[] = $this->line('poc_http_request_duration_seconds_sum', $sample['labels'], (float) $sample['value']);
        }

        foreach ($this->samples($snapshot, 'http_request_duration_seconds_count') as $sample) {
            $lines[] = $this->line('poc_http_request_duration_seconds_count', $sample['labels'], (float) $sample['value']);
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function recordedDomainMetrics(): array
    {
        $snapshot = $this->metrics->snapshot();
        $lines = [];

        foreach ($snapshot as $metric => $_samples) {
            if (! is_string($metric) || str_starts_with($metric, 'http_')) {
                continue;
            }

            $prometheusName = 'poc_'.$metric;
            $lines[] = "# HELP {$prometheusName} Application domain metric recorded by the PoC.";
            $lines[] = "# TYPE {$prometheusName} counter";

            foreach ($this->samples($snapshot, $metric) as $sample) {
                $lines[] = $this->line($prometheusName, $sample['labels'], (float) $sample['value']);
            }
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function domainMetrics(): array
    {
        $lines = [
            '# HELP poc_communications_total Communications stored by status.',
            '# TYPE poc_communications_total gauge',
        ];

        foreach (CommunicationStatus::cases() as $status) {
            $lines[] = $this->line('poc_communications_total', ['status' => $status->value], Communication::query()->where('status', $status)->count());
        }

        $lines[] = '# HELP poc_original_documents_total Original documents stored by processing status.';
        $lines[] = '# TYPE poc_original_documents_total gauge';

        foreach (ProcessingStatus::cases() as $status) {
            $lines[] = $this->line('poc_original_documents_total', ['status' => $status->value], OriginalDocument::query()->where('processing_status', $status)->count());
        }

        $lines[] = '# HELP poc_sub_documents_total Split documents stored by extraction state.';
        $lines[] = '# TYPE poc_sub_documents_total gauge';
        $lines[] = $this->line('poc_sub_documents_total', ['state' => 'total'], SubDocument::query()->count());
        $lines[] = $this->line('poc_sub_documents_total', ['state' => 'failed'], SubDocument::query()->whereNotNull('error_message')->count());

        // Metrica dedicata: i review status sono una partizione completa dei
        // sotto-documenti e non vanno mescolati alle label total/failed sopra.
        $lines[] = '# HELP poc_sub_documents_review_total Sub documents by review status.';
        $lines[] = '# TYPE poc_sub_documents_review_total gauge';

        foreach (ReviewStatus::cases() as $status) {
            $lines[] = $this->line('poc_sub_documents_review_total', ['review_status' => $status->value], SubDocument::query()->where('review_status', $status)->count());
        }

        $lines[] = '# HELP poc_document_stuck_processing_total Documents processing beyond the configured timeout.';
        $lines[] = '# TYPE poc_document_stuck_processing_total gauge';
        $lines[] = $this->line('poc_document_stuck_processing_total', [], OriginalDocument::query()
            ->where('processing_status', ProcessingStatus::Processing)
            ->where('workflow_started_at', '<', now()->subSeconds((int) config('poc.document_limits.processing_timeout_seconds', 600)))
            ->count());

        return $lines;
    }

    /**
     * @return array<string, bool>
     */
    private function readinessChecks(): array
    {
        return [
            'database' => $this->databaseReady(),
            'configuration' => filled(config('app.key')),
        ];
    }

    private function databaseReady(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{labels: array<string, string>, value: float}>
     */
    private function samples(array $snapshot, string $metric): array
    {
        $samples = [];

        foreach ($snapshot[$metric] ?? [] as $sample) {
            if (! is_array($sample) || ! is_array($sample['labels'] ?? null)) {
                continue;
            }

            $labels = array_map('strval', $sample['labels']);
            ksort($labels);
            $key = json_encode($labels, JSON_THROW_ON_ERROR);

            $samples[$key] ??= [
                'labels' => $labels,
                'value' => 0.0,
            ];
            $samples[$key]['value'] += (float) ($sample['value'] ?? 0);
        }

        usort($samples, fn (array $left, array $right): int => $this->compareLabels($left['labels'], $right['labels']));

        return array_values($samples);
    }

    /**
     * @param  array<string, string>  $left
     * @param  array<string, string>  $right
     */
    private function compareLabels(array $left, array $right): int
    {
        foreach (['route', 'method', 'status', 'check', 'state', 'le'] as $label) {
            $comparison = $label === 'le'
                ? $this->compareBucket($left[$label] ?? null, $right[$label] ?? null)
                : strcmp($left[$label] ?? '', $right[$label] ?? '');

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR));
    }

    private function compareBucket(?string $left, ?string $right): int
    {
        if ($left === $right) {
            return 0;
        }

        $leftValue = $left === '+Inf' ? INF : (float) $left;
        $rightValue = $right === '+Inf' ? INF : (float) $right;

        return $leftValue <=> $rightValue;
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    private function line(string $name, array $labels, int|float $value): string
    {
        return sprintf('%s{%s} %s', $name, $this->labels($labels), $this->value($value));
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    private function labels(array $labels): string
    {
        ksort($labels);

        return collect($labels)
            ->map(fn ($value, string $key): string => $key.'="'.$this->escapeLabel((string) $value).'"')
            ->implode(',');
    }

    private function escapeLabel(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\n', '\"'], $value);
    }

    private function value(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
