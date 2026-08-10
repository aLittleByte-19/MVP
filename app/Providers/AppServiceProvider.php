<?php

namespace App\Providers;

use App\Models\Communication;
use App\Models\OriginalDocument;
use App\Mvp\Ai\AiOutputValidator;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Services\CommunicationWorkflowTaskHandler;
use App\Mvp\Documents\Adapters\Outbound\Ai\BedrockDocumentAiAdapter;
use App\Mvp\Documents\Adapters\Outbound\Ocr\TextractOcrAdapter;
use App\Mvp\Documents\Adapters\Outbound\Pdf\DompdfSendMessageRenderer;
use App\Mvp\Documents\Adapters\Outbound\Persistence\EloquentDocumentRepository;
use App\Mvp\Documents\Adapters\Outbound\Storage\FlysystemDocumentStorageAdapter;
use App\Mvp\Documents\Adapters\Primary\Workflow\DocumentWorkflowTaskHandler;
use App\Mvp\Documents\Application\UseCases\DeleteDocumentService;
use App\Mvp\Documents\Application\UseCases\FinalizeDocumentWorkflowService;
use App\Mvp\Documents\Application\UseCases\ListDocumentsService;
use App\Mvp\Documents\Application\UseCases\ProcessDocumentService;
use App\Mvp\Documents\Application\UseCases\ReviewDocumentService;
use App\Mvp\Documents\Application\UseCases\RunOcrService;
use App\Mvp\Documents\Application\UseCases\SendMessageService;
use App\Mvp\Documents\Application\UseCases\StartDocumentWorkflowService;
use App\Mvp\Documents\Application\UseCases\UploadDocumentService;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\FinalizeDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ListDocumentsUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ReviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\SendMessageUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Adapters\Outbound\SfnWorkflowEngineAdapter;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use App\Mvp\Workflow\Services\WorkflowTaskRegistry;
use App\Mvp\Workflow\Support\WorkflowContext;
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

        // --- Dominio Documents: porta -> adapter (vedi ADR 0010) ---
        $this->app->singleton(OcrGatewayPort::class, TextractOcrAdapter::class);
        $this->app->singleton(DocumentAiGatewayPort::class, BedrockDocumentAiAdapter::class);
        $this->app->singleton(DocumentStoragePort::class, FlysystemDocumentStorageAdapter::class);
        $this->app->singleton(DocumentRepository::class, EloquentDocumentRepository::class);
        $this->app->singleton(SendMessageRendererPort::class, DompdfSendMessageRenderer::class);

        // Workflow: porta condivisa fra Documents e Communications.
        $this->app->singleton(WorkflowEnginePort::class, SfnWorkflowEngineAdapter::class);

        // Documents: porta primaria -> caso d'uso applicativo.
        $this->app->bind(UploadDocumentUseCase::class, UploadDocumentService::class);
        $this->app->bind(StartDocumentWorkflowUseCase::class, StartDocumentWorkflowService::class);
        $this->app->bind(ListDocumentsUseCase::class, ListDocumentsService::class);
        $this->app->bind(DeleteDocumentUseCase::class, DeleteDocumentService::class);
        $this->app->bind(RunOcrUseCase::class, RunOcrService::class);
        $this->app->bind(ProcessDocumentUseCase::class, ProcessDocumentService::class);
        $this->app->bind(FinalizeDocumentWorkflowUseCase::class, FinalizeDocumentWorkflowService::class);
        $this->app->bind(ReviewDocumentUseCase::class, ReviewDocumentService::class);
        $this->app->bind(SendMessageUseCase::class, SendMessageService::class);

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
