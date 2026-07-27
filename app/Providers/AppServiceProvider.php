<?php

namespace App\Providers;

use App\Copilot\Ai\AiOutputValidator;
use App\Copilot\Ai\BedrockService;
use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Services\CommunicationWorkflowTaskHandler;
use App\Copilot\Documents\Services\DocumentProcessingService;
use App\Copilot\Documents\Services\DocumentWorkflowTaskHandler;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Ocr\Services\TextractService;
use App\Copilot\Workflow\Services\WorkflowTaskHeartbeat;
use App\Copilot\Workflow\Services\WorkflowTaskRegistry;
use App\Copilot\Workflow\Support\WorkflowContext;
use App\Models\Copilot\Communication;
use App\Models\Copilot\OriginalDocument;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sfn\SfnClient;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BedrockRuntimeClient::class, function () {
            return $this->bedrockClient((string) config('services.bedrock.region'));
        });

        // Client separato per i modelli immagine: sono attivi in un insieme di
        // region diverso da quelli testo. Con la stessa region la connessione e'
        // equivalente, quindi non serve un interruttore per unificarli.
        $this->app->singleton('bedrock.image_client', function () {
            return $this->bedrockClient((string) config('services.bedrock.image_region'));
        });

        $this->app->singleton(BedrockService::class, function ($app) {
            return new BedrockService(
                $app->make(BedrockRuntimeClient::class),
                $app->make('bedrock.image_client'),
                config('services.bedrock.model_id'),
                config('services.bedrock.image_model_id'),
                $app->make(AiOutputValidator::class),
                $app->make(WorkflowTaskHeartbeat::class),
            );
        });

        $this->app->singleton(SfnClient::class, function () {
            $config = [
                'version' => 'latest',
                'region' => config('services.workflow.region'),
            ];

            if (filled(config('services.workflow.endpoint'))) {
                $config['endpoint'] = config('services.workflow.endpoint');
            }

            return new SfnClient($config);
        });

        $this->app->singleton(SqsClient::class, function () {
            $config = [
                'version' => 'latest',
                'region' => config('services.sqs.region'),
            ];

            if (filled(config('services.sqs.endpoint'))) {
                $config['endpoint'] = config('services.sqs.endpoint');
            }

            return new SqsClient($config);
        });

        $this->app->singleton(TextractClient::class, function () {
            $config = [
                'version' => 'latest',
                'region' => config('services.textract.region'),
                'http' => [
                    'timeout' => (int) config('services.textract.timeout_seconds', 300) + 30,
                    'connect_timeout' => 15,
                ],
            ];

            $credentials = array_filter((array) config('services.textract.credentials'));

            if ($credentials !== []) {
                $config['credentials'] = $credentials;
            }

            return new TextractClient($config);
        });

        // Singleton: il consumer la attiva per messaggio e i service di lunga
        // durata (Textract, split Bedrock) devono condividere la stessa istanza.
        $this->app->singleton(WorkflowTaskHeartbeat::class, function ($app) {
            return new WorkflowTaskHeartbeat(
                $app->make(SfnClient::class),
                $app->make(MetricsRecorder::class),
            );
        });

        $this->app->singleton(TextractService::class, function ($app) {
            return new TextractService(
                $app->make(TextractClient::class),
                $app->make(MetricsRecorder::class),
                $app->make(WorkflowTaskHeartbeat::class),
            );
        });

        $this->app->singleton(DocumentProcessingService::class, function ($app) {
            return new DocumentProcessingService(
                $app->make(BedrockService::class),
                $app->make(AuditLogger::class),
                $app->make(WorkflowTaskHeartbeat::class),
                $app->make(MetricsRecorder::class),
            );
        });

        // Correlation id del messaggio in lavorazione: singleton perche' il
        // consumer lo popola e AuditLogger lo rilegge nello stesso processo.
        $this->app->singleton(WorkflowContext::class);

        // Routing dei task di callback: ogni dominio registra qui i propri task
        // type, il runner resta agnostico.
        $this->app->singleton(WorkflowTaskRegistry::class, function ($app) {
            $registry = new WorkflowTaskRegistry;
            $registry->register($app->make(DocumentWorkflowTaskHandler::class));
            $registry->register($app->make(CommunicationWorkflowTaskHandler::class));

            return $registry;
        });
    }

    private function bedrockClient(string $region): BedrockRuntimeClient
    {
        $config = [
            'version' => 'latest',
            'region' => $region,
            'http' => [
                'timeout' => 300,
                'connect_timeout' => 15,
            ],
        ];

        $credentials = array_filter((array) config('services.bedrock.credentials'));

        if ($credentials !== []) {
            $config['credentials'] = $credentials;
        }

        if (filled(config('services.bedrock.endpoint'))) {
            $config['endpoint'] = config('services.bedrock.endpoint');
        }

        return new BedrockRuntimeClient($config);
    }

    public function boot(): void
    {
        // Alias morph stabili: workflow_tasks.subject_type conserva queste
        // stringhe invece dei FQCN, che cambierebbero a ogni refactor.
        Relation::morphMap([
            'original_document' => OriginalDocument::class,
            'communication' => Communication::class,
        ]);
    }
}
