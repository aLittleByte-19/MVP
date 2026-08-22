<?php

namespace App\Mvp\Observability;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageSource;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
use App\Mvp\Workflow\Support\StateMachineName;

/**
 * Elenco dichiarativo delle metriche di dominio esposte da /internal/metrics.
 *
 * Esiste per tre ragioni concrete, tutte emerse da difetti reali:
 * 1. una metrica mai emessa non compariva affatto nell'esposizione, quindi i
 *    pannelli mostravano "No data" e le regole con `== 0` non avevano nulla su
 *    cui valutare (e' il motivo per cui QueueBacklogHigh non poteva scattare);
 * 2. PrometheusExporter dichiarava `# TYPE ... counter` per qualunque chiave
 *    trovasse nel file di accumulo, comprese quelle non piu' emesse dal codice;
 * 3. senza un elenco dichiarato, nessun test poteva confrontare cio' che il
 *    codice emette con cio' che dashboard e alert interrogano.
 *
 * I valori enumerati derivano dagli enum di dominio invece di essere ripetuti
 * come stringhe: se un enum cambia, cambia anche la serie esposta, e il test
 * di contratto se ne accorge invece di far comparire una riga vuota in Grafana.
 *
 * Sta in Observability, che ADR 0010 colloca esplicitamente fuori dal perimetro
 * ports & adapters: e' una classe concreta, non una porta, perche' non avra'
 * mai una seconda implementazione.
 */
final class DomainMetricCatalog
{
    /**
     * Task type delle due pipeline. Ripetuti qui invece di essere risolti dal
     * WorkflowTaskRegistry a runtime, per tenere il catalogo dichiarativo:
     * ObservabilityContractTest verifica che i due elenchi coincidano, cosi'
     * un task type aggiunto senza aggiornare il catalogo rompe la suite invece
     * di sparire silenziosamente dalle dashboard.
     *
     * @var list<string>
     */
    public const TASK_TYPES = [
        'bedrock.extract',
        'communication.finalize',
        'communication.generate_cover',
        'communication.generate_text',
        'dispatch.domain_event',
        'persist.results',
        'textract.ocr',
    ];

    /**
     * Le due pipeline di dominio, nella forma accettata da
     * StateMachineName::forPipeline().
     *
     * @var list<string>
     */
    public const PIPELINES = ['communications', 'documents'];

    /**
     * Le DLQ sono una per pipeline e ne portano il nome: l'insieme e' lo
     * stesso, e tenerli legati evita che una pipeline aggiunta domani compaia
     * nelle serie delle state machine ma non in quelle delle code.
     *
     * @var list<string>
     */
    public const DLQ_QUEUES = self::PIPELINES;

    /** @var list<string> */
    public const WORKFLOW_FAILURE_REASONS = ['start_failure', 'task_failure'];

    /**
     * Stati di claim di un task, come li scrive WorkflowTaskRunner.
     *
     * @var list<string>
     */
    public const WORKFLOW_TASK_STATUSES = ['failed', 'pending', 'running', 'skipped', 'succeeded'];

    /**
     * Metriche accumulate da MetricsRecorder e lette dal file condiviso.
     *
     * @return array<string, DomainMetricDefinition>
     */
    public static function recorded(): array
    {
        $stateMachines = self::stateMachineNames();

        $definitions = [
            new DomainMetricDefinition(
                'stepfunctions_executions_started_total',
                'counter',
                'Step Functions executions started by the application.',
                ['state_machine'],
                ['state_machine' => $stateMachines],
            ),
            new DomainMetricDefinition(
                'stepfunctions_executions_failed_total',
                'counter',
                'Step Functions executions that failed to start or whose task failed.',
                ['reason', 'state_machine'],
                ['reason' => self::WORKFLOW_FAILURE_REASONS, 'state_machine' => $stateMachines],
            ),
            new DomainMetricDefinition(
                'stepfunctions_callbacks_failed_total',
                'counter',
                'Step Functions callbacks rejected by the service.',
                ['error', 'operation'],
            ),
            new DomainMetricDefinition(
                'stepfunctions_heartbeats_sent_total',
                'counter',
                'Task heartbeats sent to Step Functions.',
                ['task_type'],
                ['task_type' => self::TASK_TYPES],
            ),
            new DomainMetricDefinition(
                'stepfunctions_heartbeats_failed_total',
                'counter',
                'Task heartbeats rejected by Step Functions.',
                ['error', 'task_type'],
            ),
            new DomainMetricDefinition(
                'sqs_messages_received_total',
                'counter',
                'Workflow task messages consumed from SQS.',
                ['task_type'],
                ['task_type' => self::TASK_TYPES],
            ),
            new DomainMetricDefinition(
                'sqs_messages_failed_total',
                'counter',
                'Workflow task messages whose handling failed.',
                ['task_type'],
                ['task_type' => self::TASK_TYPES],
            ),
            new DomainMetricDefinition(
                'sqs_messages_duplicate_total',
                'counter',
                'Workflow task messages skipped as duplicates.',
                ['task_type'],
                ['task_type' => self::TASK_TYPES],
            ),
            new DomainMetricDefinition(
                'textract_jobs_started_total',
                'counter',
                'Textract OCR jobs started.',
            ),
            new DomainMetricDefinition(
                'textract_jobs_completed_total',
                'counter',
                'Textract OCR jobs completed successfully.',
            ),
            new DomainMetricDefinition(
                'textract_jobs_failed_total',
                'counter',
                'Textract OCR jobs failed, by AWS error code.',
                ['error'],
            ),
            new DomainMetricDefinition(
                'textract_jobs_skipped_total',
                'counter',
                'Textract OCR jobs skipped.',
                ['reason'],
                ['reason' => ['disabled']],
            ),
            // Summary, non due counter separati: il nome di famiglia dichiara
            // che _sum e _count appartengono alla stessa misura. Un counter il
            // cui nome finisce in _sum viene rinominato in ..._sum_total dal
            // prometheusexporter del Collector quando add_metric_suffixes e'
            // attivo — e' esattamente cio' che ha reso vuoti i pannelli OCR.
            new DomainMetricDefinition(
                'textract_confidence',
                'summary',
                'Average OCR confidence reported by Textract.',
            ),
            new DomainMetricDefinition(
                'textract_duration_seconds',
                'summary',
                'Textract OCR job duration in seconds.',
            ),
            new DomainMetricDefinition(
                'ai_outputs_invalid_total',
                'counter',
                'AI outputs rejected by the schema validator, by operation.',
                ['operation'],
            ),
            new DomainMetricDefinition(
                'ai_extractions_total',
                'counter',
                'Field extractions completed, by resulting review status.',
                ['review_status'],
                ['review_status' => self::enumValues(ReviewStatus::cases())],
            ),
            new DomainMetricDefinition(
                'communication_covers_generated_total',
                'counter',
                'Communication covers stored, by source.',
                ['source'],
                ['source' => self::enumValues(CoverImageSource::cases())],
            ),
            new DomainMetricDefinition(
                'communication_covers_failed_total',
                'counter',
                'Communication cover generations that degraded, by reason.',
                ['reason'],
            ),
            new DomainMetricDefinition(
                'communication_cover_storage_failed_total',
                'counter',
                'Cover storage operations that failed, by operation.',
                ['operation'],
                ['operation' => ['delete', 'exists', 'get', 'put']],
            ),
            new DomainMetricDefinition(
                'document_workflow_completed_total',
                'counter',
                'Document workflows that reached completion.',
            ),
            new DomainMetricDefinition(
                'communication_workflow_completed_total',
                'counter',
                'Communication workflows that reached completion.',
            ),
            new DomainMetricDefinition(
                'metrics_collection_failures_total',
                'counter',
                'Metric collectors that failed while rendering the exposition.',
                ['collector'],
            ),
        ];

        return self::keyByName($definitions);
    }

    /**
     * Metriche calcolate a ogni scrape (stato corrente, non accumulo).
     *
     * @return array<string, DomainMetricDefinition>
     */
    public static function gauges(): array
    {
        $definitions = [
            new DomainMetricDefinition(
                'communications',
                'gauge',
                'Communications stored by draft decision status.',
                ['status'],
                ['status' => self::enumValues(CommunicationStatus::cases())],
            ),
            new DomainMetricDefinition(
                'original_documents',
                'gauge',
                'Original documents stored by processing status.',
                ['status'],
                ['status' => self::enumValues(ProcessingStatus::cases())],
            ),
            new DomainMetricDefinition(
                'sub_documents',
                'gauge',
                'Split documents stored.',
            ),
            new DomainMetricDefinition(
                'sub_documents_failed',
                'gauge',
                'Split documents carrying an extraction error.',
            ),
            new DomainMetricDefinition(
                'sub_documents_review',
                'gauge',
                'Sub documents by review status.',
                ['review_status'],
                ['review_status' => self::enumValues(ReviewStatus::cases())],
            ),
            new DomainMetricDefinition(
                'sub_documents_send',
                'gauge',
                'Sub documents by send status (sent means the PDF was downloaded).',
                ['send_status'],
                ['send_status' => self::enumValues(SendStatus::cases())],
            ),
            new DomainMetricDefinition(
                'documents_stuck_processing',
                'gauge',
                'Documents processing beyond the configured timeout.',
            ),
            new DomainMetricDefinition(
                'communications_generation',
                'gauge',
                'Communications by generation pipeline status.',
                ['generation_status'],
                ['generation_status' => self::enumValues(CommunicationGenerationStatus::cases())],
            ),
            new DomainMetricDefinition(
                'communication_covers',
                'gauge',
                'Communication covers by status.',
                ['cover_status'],
                ['cover_status' => self::enumValues(CoverImageStatus::cases())],
            ),
            new DomainMetricDefinition(
                'communications_rated',
                'gauge',
                'Communications that received a rating.',
            ),
            new DomainMetricDefinition(
                'communication_rating_average',
                'gauge',
                'Average star rating across rated communications.',
            ),
            new DomainMetricDefinition(
                'communications_stuck_processing',
                'gauge',
                'Communications generating beyond the configured timeout.',
            ),
            // Saturazione del trasporto: quanti task attendono un worker.
            // Complementa la profondita' DLQ, che misura invece cio' che ha
            // gia' smesso di essere ritentato.
            new DomainMetricDefinition(
                'workflow_tasks',
                'gauge',
                'Workflow tasks by claim status.',
                ['status'],
                ['status' => self::WORKFLOW_TASK_STATUSES],
            ),
            new DomainMetricDefinition(
                'dlq_messages',
                'gauge',
                'Messages currently held in the workflow dead letter queues.',
                ['queue'],
            ),
            new DomainMetricDefinition(
                'dlq_probe_up',
                'gauge',
                'Whether the dead letter queue depth could be read (1) or not (0).',
                ['queue'],
                ['queue' => self::DLQ_QUEUES],
            ),
            new DomainMetricDefinition(
                'readiness_status',
                'gauge',
                'Readiness check status by dependency.',
                ['check'],
            ),
            new DomainMetricDefinition(
                'app_info',
                'gauge',
                'Static application metadata.',
                ['deployment_environment', 'service_name', 'service_namespace', 'service_version'],
            ),
        ];

        return self::keyByName($definitions);
    }

    /**
     * @return array<string, DomainMetricDefinition>
     */
    public static function all(): array
    {
        return array_merge(self::recorded(), self::gauges());
    }

    /**
     * Nomi accettati da MetricsRecorder: le famiglie summary sono espanse nei
     * due sample che il codice scrive davvero (`_sum` e `_count`).
     *
     * @return array<string, list<string>> nome scritto dal codice => label ammesse
     */
    public static function recordableLabelNames(): array
    {
        $names = [];

        foreach (self::recorded() as $definition) {
            if ($definition->type === 'summary') {
                $names[$definition->name.'_sum'] = $definition->labelNames;
                $names[$definition->name.'_count'] = $definition->labelNames;

                continue;
            }

            $names[$definition->name] = $definition->labelNames;
        }

        return $names;
    }

    /**
     * Nomi brevi delle state machine configurate in questo ambiente.
     *
     * Letti dalla configurazione invece che elencati come costanti: portano il
     * name_prefix dell'ambiente (`mvp-document-pipeline` in locale), quindi un
     * elenco fisso produrrebbe serie a zero con nomi che non esistono da
     * nessuna parte. Ricavandoli da `StateMachineName::forPipeline()` — la
     * stessa funzione che etichetta le metriche a runtime — la serie seminata
     * e quella emessa dopo il primo evento reale portano per costruzione la
     * stessa label, che e' il punto: senza, i pannelli filtrati per
     * `state_machine` restavano su "No data" invece di mostrare uno zero.
     *
     * Una pipeline senza ARN configurato resta fuori. `unknown` e' un valore
     * che il codice emette davvero quando l'ARN manca, ma seminarlo a zero
     * descriverebbe una pipeline che in quell'ambiente non puo' partire.
     *
     * @return list<string>
     */
    private static function stateMachineNames(): array
    {
        $names = [];

        foreach (self::PIPELINES as $pipeline) {
            $name = StateMachineName::forPipeline($pipeline);

            if ($name !== StateMachineName::UNKNOWN) {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<string>
     */
    private static function enumValues(array $cases): array
    {
        $values = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
        sort($values);

        return array_values($values);
    }

    /**
     * @param  list<DomainMetricDefinition>  $definitions
     * @return array<string, DomainMetricDefinition>
     */
    private static function keyByName(array $definitions): array
    {
        $keyed = [];

        foreach ($definitions as $definition) {
            $keyed[$definition->name] = $definition;
        }

        return $keyed;
    }
}
