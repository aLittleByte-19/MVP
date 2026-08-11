<?php

namespace App\Mvp\Observability;

use App\Models\Communication;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Documents\Enums\SendStatus;
use Illuminate\Support\Facades\DB;

class PrometheusExporter
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function render(): string
    {
        $lines = [
            '# HELP mvp_app_info Static application metadata.',
            '# TYPE mvp_app_info gauge',
            $this->line('mvp_app_info', [
                'service_name' => (string) config('observability.service.name'),
                'service_namespace' => (string) config('observability.service.namespace'),
                'service_version' => (string) config('observability.service.version'),
                'deployment_environment' => (string) config('observability.service.environment'),
            ], 1),
            '# HELP mvp_readiness_status Readiness check status by dependency.',
            '# TYPE mvp_readiness_status gauge',
        ];

        foreach ($this->readinessChecks() as $check => $ready) {
            $lines[] = $this->line('mvp_readiness_status', ['check' => $check], $ready ? 1 : 0);
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
            '# HELP mvp_http_requests_total Total HTTP requests handled by the application.',
            '# TYPE mvp_http_requests_total counter',
        ];

        foreach ($this->samples($snapshot, 'http_requests_total') as $sample) {
            $lines[] = $this->line('mvp_http_requests_total', $sample['labels'], (float) $sample['value']);
        }

        $lines[] = '# HELP mvp_http_request_duration_seconds HTTP request duration histogram.';
        $lines[] = '# TYPE mvp_http_request_duration_seconds histogram';

        foreach ($this->samples($snapshot, 'http_request_duration_seconds_bucket') as $sample) {
            $lines[] = $this->line('mvp_http_request_duration_seconds_bucket', $sample['labels'], (float) $sample['value']);
        }

        foreach ($this->samples($snapshot, 'http_request_duration_seconds_sum') as $sample) {
            $lines[] = $this->line('mvp_http_request_duration_seconds_sum', $sample['labels'], (float) $sample['value']);
        }

        foreach ($this->samples($snapshot, 'http_request_duration_seconds_count') as $sample) {
            $lines[] = $this->line('mvp_http_request_duration_seconds_count', $sample['labels'], (float) $sample['value']);
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

            $prometheusName = 'mvp_'.$metric;
            $lines[] = "# HELP {$prometheusName} Application domain metric recorded by the MVP.";
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
            '# HELP mvp_communications_total Communications stored by status.',
            '# TYPE mvp_communications_total gauge',
        ];

        foreach (CommunicationStatus::cases() as $status) {
            $lines[] = $this->line('mvp_communications_total', ['status' => $status->value], Communication::query()->where('status', $status)->count());
        }

        $lines[] = '# HELP mvp_original_documents_total Original documents stored by processing status.';
        $lines[] = '# TYPE mvp_original_documents_total gauge';

        foreach (ProcessingStatus::cases() as $status) {
            $lines[] = $this->line('mvp_original_documents_total', ['status' => $status->value], OriginalDocument::query()->where('processing_status', $status)->count());
        }

        $lines[] = '# HELP mvp_sub_documents_total Split documents stored by extraction state.';
        $lines[] = '# TYPE mvp_sub_documents_total gauge';
        $lines[] = $this->line('mvp_sub_documents_total', ['state' => 'total'], SubDocument::query()->count());
        $lines[] = $this->line('mvp_sub_documents_total', ['state' => 'failed'], SubDocument::query()->whereNotNull('error_message')->count());

        // Metrica dedicata: i review status sono una partizione completa dei
        // sotto-documenti e non vanno mescolati alle label total/failed sopra.
        $lines[] = '# HELP mvp_sub_documents_review_total Sub documents by review status.';
        $lines[] = '# TYPE mvp_sub_documents_review_total gauge';

        foreach (ReviewStatus::cases() as $status) {
            $lines[] = $this->line('mvp_sub_documents_review_total', ['review_status' => $status->value], SubDocument::query()->where('review_status', $status)->count());
        }

        // Lo stato di invio coincide con l'avvenuto scaricamento del PDF: il
        // recapito e' fuori piattaforma, quindi il download e' l'ultimo evento
        // che possiamo osservare.
        $lines[] = '# HELP mvp_sub_documents_send_total Sub documents by send status (sent means the PDF was downloaded).';
        $lines[] = '# TYPE mvp_sub_documents_send_total gauge';

        foreach (SendStatus::cases() as $status) {
            $lines[] = $this->line('mvp_sub_documents_send_total', ['send_status' => $status->value], SubDocument::query()->where('send_status', $status)->count());
        }

        $lines[] = '# HELP mvp_document_stuck_processing_total Documents processing beyond the configured timeout.';
        $lines[] = '# TYPE mvp_document_stuck_processing_total gauge';
        $lines[] = $this->line('mvp_document_stuck_processing_total', [], OriginalDocument::query()
            ->where('processing_status', ProcessingStatus::Processing)
            ->where('workflow_started_at', '<', now()->subSeconds((int) config('mvp.document_limits.processing_timeout_seconds', 600)))
            ->count());

        // Stato tecnico della pipeline di generazione, distinto da
        // mvp_communications_total che conta la decisione sulla bozza.
        $lines[] = '# HELP mvp_communications_generation_total Communications by generation pipeline status.';
        $lines[] = '# TYPE mvp_communications_generation_total gauge';

        foreach (CommunicationGenerationStatus::cases() as $status) {
            $lines[] = $this->line('mvp_communications_generation_total', ['generation_status' => $status->value], Communication::query()->where('generation_status', $status)->count());
        }

        $lines[] = '# HELP mvp_communication_covers_total Communication covers by status.';
        $lines[] = '# TYPE mvp_communication_covers_total gauge';

        foreach (CoverImageStatus::cases() as $status) {
            $lines[] = $this->line('mvp_communication_covers_total', ['cover_status' => $status->value], Communication::query()->where('cover_status', $status)->count());
        }

        // Qualita' percepita della generazione: una sola valutazione per bozza,
        // quindi il conteggio e' anche il numero di bozze valutate.
        $lines[] = '# HELP mvp_communications_rated_total Communications that received a rating.';
        $lines[] = '# TYPE mvp_communications_rated_total gauge';
        $lines[] = $this->line('mvp_communications_rated_total', [], Communication::query()->whereNotNull('rating')->count());

        $lines[] = '# HELP mvp_communication_rating_average Average star rating across rated communications.';
        $lines[] = '# TYPE mvp_communication_rating_average gauge';
        $lines[] = $this->line('mvp_communication_rating_average', [], round((float) (Communication::query()->whereNotNull('rating')->avg('rating') ?? 0), 2));

        $lines[] = '# HELP mvp_communication_stuck_processing_total Communications generating beyond the configured timeout.';
        $lines[] = '# TYPE mvp_communication_stuck_processing_total gauge';
        $lines[] = $this->line('mvp_communication_stuck_processing_total', [], Communication::query()
            ->where('generation_status', CommunicationGenerationStatus::Processing)
            ->where('workflow_started_at', '<', now()->subSeconds((int) config('mvp.communications.generation_timeout_seconds', 300)))
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
