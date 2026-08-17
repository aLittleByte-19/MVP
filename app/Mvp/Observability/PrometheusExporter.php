<?php

namespace App\Mvp\Observability;

use App\Models\Communication;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Models\WorkflowTask;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PrometheusExporter
{
    /**
     * Fallimenti dei collector durante il render corrente, per esporli come
     * metrica invece di perderli (vedi collect()).
     *
     * @var array<string, int>
     */
    private array $collectionFailures = [];

    public function __construct(
        private readonly MetricsRecorder $metrics,
        private readonly DlqDepthProbe $dlq,
    ) {}

    public function render(): string
    {
        $this->collectionFailures = [];

        // Una sola lettura del file condiviso per scrape: prima ne servivano
        // due (metriche HTTP e metriche di dominio), ognuna con la propria
        // acquisizione di lock.
        $snapshot = $this->metrics->snapshot();

        $lines = array_merge(
            $this->appInfo(),
            $this->readinessMetrics(),
            $this->httpMetrics($snapshot),
            $this->gaugeMetrics(),
            $this->recordedDomainMetrics($snapshot),
        );

        return implode("\n", array_merge($lines, $this->collectionFailureMetrics()))."\n";
    }

    /**
     * Esegue un collector isolandone il fallimento: una query rotta deve far
     * mancare la propria famiglia di metriche, non l'intera esposizione. Prima
     * bastava una colonna assente perche' /internal/metrics rispondesse 500 e
     * Prometheus perdesse anche le metriche HTTP e di readiness, che stavano
     * funzionando — cioe' l'osservabilita' spariva proprio durante un deploy
     * andato a meta'.
     *
     * @param  callable(): array<int, string>  $collector
     * @return array<int, string>
     */
    private function collect(string $name, callable $collector): array
    {
        try {
            return $collector();
        } catch (\Throwable $e) {
            $this->collectionFailures[$name] = ($this->collectionFailures[$name] ?? 0) + 1;

            Log::warning('Metric collector failed', [
                'collector' => $name,
                'message' => str($e->getMessage())->limit(220)->toString(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectionFailureMetrics(): array
    {
        $definition = DomainMetricCatalog::recorded()['metrics_collection_failures_total'];
        $lines = $this->header($definition);

        foreach ($this->collectionFailures as $collector => $count) {
            $lines[] = $this->line('mvp_metrics_collection_failures_total', ['collector' => $collector], $count);
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function appInfo(): array
    {
        $definition = DomainMetricCatalog::gauges()['app_info'];

        return array_merge($this->header($definition), [
            $this->line('mvp_app_info', [
                'service_name' => (string) config('observability.service.name'),
                'service_namespace' => (string) config('observability.service.namespace'),
                'service_version' => (string) config('observability.service.version'),
                'deployment_environment' => (string) config('observability.service.environment'),
            ], 1),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function readinessMetrics(): array
    {
        $definition = DomainMetricCatalog::gauges()['readiness_status'];
        $lines = $this->header($definition);

        foreach ($this->readinessChecks() as $check => $ready) {
            $lines[] = $this->line('mvp_readiness_status', ['check' => $check], $ready ? 1 : 0);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function httpMetrics(array $snapshot): array
    {
        $lines = [
            '# HELP mvp_http_requests_total Total HTTP requests handled by the application.',
            '# TYPE mvp_http_requests_total counter',
        ];

        foreach ($this->samples($snapshot, 'http_requests_total') as $sample) {
            $lines[] = $this->line('mvp_http_requests_total', $sample['labels'], (float) $sample['value']);
        }

        $lines[] = '# HELP mvp_http_request_duration_seconds HTTP request duration histogram.';
        $lines[] = '# TYPE mvp_http_request_duration_seconds histogram';

        foreach (['bucket', 'sum', 'count'] as $suffix) {
            foreach ($this->samples($snapshot, 'http_request_duration_seconds_'.$suffix) as $sample) {
                $lines[] = $this->line('mvp_http_request_duration_seconds_'.$suffix, $sample['labels'], (float) $sample['value']);
            }
        }

        return $lines;
    }

    /**
     * Rende le metriche accumulate iterando il catalogo, non il file: una
     * metrica dichiarata ma mai emessa esce comunque, a zero, sulle
     * combinazioni di label note. Senza, `increase(...) == 0` non aveva serie
     * su cui valutare e i pannelli mostravano "No data" al posto di uno zero.
     * Le chiavi presenti nel file ma non a catalogo sono ignorate: e' cosi'
     * che una metrica ritirata smette davvero di essere esposta, invece di
     * sopravvivere per sempre nel volume condiviso.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function recordedDomainMetrics(array $snapshot): array
    {
        $lines = [];

        foreach (DomainMetricCatalog::recorded() as $definition) {
            if ($definition->name === 'metrics_collection_failures_total') {
                continue;
            }

            $lines = array_merge($lines, $this->header($definition));

            foreach ($this->sampleNames($definition) as $sampleName) {
                $lines = array_merge($lines, $this->recordedSamples($snapshot, $definition, $sampleName));
            }
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function sampleNames(DomainMetricDefinition $definition): array
    {
        return $definition->type === 'summary'
            ? [$definition->name.'_sum', $definition->name.'_count']
            : [$definition->name];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    private function recordedSamples(array $snapshot, DomainMetricDefinition $definition, string $sampleName): array
    {
        $observed = [];

        foreach ($this->samples($snapshot, $sampleName) as $sample) {
            $observed[$this->labelKey($sample['labels'])] = $sample;
        }

        foreach ($definition->seedLabelSets() as $labels) {
            $key = $this->labelKey($labels);
            $observed[$key] ??= ['labels' => $labels, 'value' => 0.0];
        }

        $samples = array_values($observed);
        usort($samples, fn (array $left, array $right): int => $this->compareLabels($left['labels'], $right['labels']));

        return array_map(
            fn (array $sample): string => $this->line('mvp_'.$sampleName, $sample['labels'], (float) $sample['value']),
            $samples,
        );
    }

    /**
     * Gauge di stato: ogni famiglia e' raccolta a se' stante, cosi' una query
     * che fallisce non trascina giu' le altre (vedi collect()).
     *
     * @return array<int, string>
     */
    private function gaugeMetrics(): array
    {
        $gauges = DomainMetricCatalog::gauges();

        return array_merge(
            $this->collect('communications', fn (): array => $this->enumGauge(
                $gauges['communications'],
                CommunicationStatus::cases(),
                'status',
                fn ($status): int => Communication::query()->where('status', $status)->count(),
            )),
            $this->collect('original_documents', fn (): array => $this->enumGauge(
                $gauges['original_documents'],
                ProcessingStatus::cases(),
                'status',
                fn ($status): int => OriginalDocument::query()->where('processing_status', $status)->count(),
            )),
            $this->collect('sub_documents', fn (): array => array_merge(
                $this->header($gauges['sub_documents']),
                [$this->line('mvp_sub_documents', [], SubDocument::query()->count())],
                $this->header($gauges['sub_documents_failed']),
                [$this->line('mvp_sub_documents_failed', [], SubDocument::query()->whereNotNull('error_message')->count())],
            )),
            $this->collect('sub_documents_review', fn (): array => $this->enumGauge(
                $gauges['sub_documents_review'],
                ReviewStatus::cases(),
                'review_status',
                fn ($status): int => SubDocument::query()->where('review_status', $status)->count(),
            )),
            $this->collect('sub_documents_send', fn (): array => $this->enumGauge(
                $gauges['sub_documents_send'],
                SendStatus::cases(),
                'send_status',
                fn ($status): int => SubDocument::query()->where('send_status', $status)->count(),
            )),
            $this->collect('documents_stuck_processing', fn (): array => array_merge(
                $this->header($gauges['documents_stuck_processing']),
                [$this->line('mvp_documents_stuck_processing', [], OriginalDocument::query()
                    ->where('processing_status', ProcessingStatus::Processing)
                    ->where('workflow_started_at', '<', now()->subSeconds((int) config('mvp.document_limits.processing_timeout_seconds', 600)))
                    ->count())],
            )),
            $this->collect('communications_generation', fn (): array => $this->enumGauge(
                $gauges['communications_generation'],
                CommunicationGenerationStatus::cases(),
                'generation_status',
                fn ($status): int => Communication::query()->where('generation_status', $status)->count(),
            )),
            $this->collect('communication_covers', fn (): array => $this->enumGauge(
                $gauges['communication_covers'],
                CoverImageStatus::cases(),
                'cover_status',
                fn ($status): int => Communication::query()->where('cover_status', $status)->count(),
            )),
            $this->collect('communications_rated', fn (): array => array_merge(
                $this->header($gauges['communications_rated']),
                [$this->line('mvp_communications_rated', [], Communication::query()->whereNotNull('rating')->count())],
                $this->header($gauges['communication_rating_average']),
                [$this->line('mvp_communication_rating_average', [], round((float) (Communication::query()->whereNotNull('rating')->avg('rating') ?? 0), 2))],
            )),
            $this->collect('communications_stuck_processing', fn (): array => array_merge(
                $this->header($gauges['communications_stuck_processing']),
                [$this->line('mvp_communications_stuck_processing', [], Communication::query()
                    ->where('generation_status', CommunicationGenerationStatus::Processing)
                    ->where('workflow_started_at', '<', now()->subSeconds((int) config('mvp.communications.generation_timeout_seconds', 300)))
                    ->count())],
            )),
            $this->collect('workflow_tasks', fn (): array => $this->workflowTaskMetrics($gauges['workflow_tasks'])),
            $this->collect('dlq', fn (): array => $this->dlqMetrics($gauges)),
        );
    }

    /**
     * Task per stato di claim: `pending` e `running` dicono quanto lavoro
     * attende o e' in corso, cioe' la saturazione del trasporto. E' la misura
     * che manca alla DLQ, la quale conta solo cio' che ha smesso di essere
     * ritentato.
     *
     * Una sola query aggregata invece di cinque `count()`: gli stati sono
     * completati a zero dal catalogo, cosi' una serie assente non diventa un
     * buco nel grafico.
     *
     * @return array<int, string>
     */
    private function workflowTaskMetrics(DomainMetricDefinition $definition): array
    {
        $counts = WorkflowTask::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $lines = $this->header($definition);

        foreach (DomainMetricCatalog::WORKFLOW_TASK_STATUSES as $status) {
            $lines[] = $this->line('mvp_workflow_tasks', ['status' => $status], (int) ($counts[$status] ?? 0));
        }

        return $lines;
    }

    /**
     * @param  array<string, DomainMetricDefinition>  $gauges
     * @return array<int, string>
     */
    private function dlqMetrics(array $gauges): array
    {
        $depths = $this->dlq->depths();
        $depthLines = $this->header($gauges['dlq_messages']);
        $probeLines = $this->header($gauges['dlq_probe_up']);

        foreach ($depths as $queue => $depth) {
            $probeLines[] = $this->line('mvp_dlq_probe_up', ['queue' => $queue], $depth === null ? 0 : 1);

            // Nessuna serie di profondita' quando la lettura non e' riuscita:
            // uno zero inventato spegnerebbe l'alert critical sulla DLQ.
            if ($depth !== null) {
                $depthLines[] = $this->line('mvp_dlq_messages', ['queue' => $queue], $depth);
            }
        }

        return array_merge($depthLines, $probeLines);
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @param  callable(\BackedEnum): int  $count
     * @return array<int, string>
     */
    private function enumGauge(DomainMetricDefinition $definition, array $cases, string $label, callable $count): array
    {
        $lines = $this->header($definition);

        foreach ($cases as $case) {
            $lines[] = $this->line('mvp_'.$definition->name, [$label => (string) $case->value], $count($case));
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function header(DomainMetricDefinition $definition): array
    {
        return [
            "# HELP mvp_{$definition->name} {$definition->help}",
            "# TYPE mvp_{$definition->name} {$definition->type}",
        ];
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
     * @param  array<string, string>  $labels
     */
    private function labelKey(array $labels): string
    {
        ksort($labels);

        return json_encode($labels, JSON_THROW_ON_ERROR);
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
        return $labels === []
            ? sprintf('%s %s', $name, $this->value($value))
            : sprintf('%s{%s} %s', $name, $this->labels($labels), $this->value($value));
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
