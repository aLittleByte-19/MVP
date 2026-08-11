<?php

namespace App\Providers;

use App\Models\Communication;
use App\Models\OriginalDocument;
use App\Mvp\Ai\AiOutputValidator;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Communications\Adapters\Outbound\Ai\BedrockCommunicationAiAdapter;
use App\Mvp\Communications\Adapters\Outbound\Events\LaravelCommunicationEventDispatcher;
use App\Mvp\Communications\Adapters\Outbound\Pdf\DompdfCommunicationPdfRenderer;
use App\Mvp\Communications\Adapters\Outbound\Persistence\EloquentCommunicationRepository;
use App\Mvp\Communications\Adapters\Outbound\Persistence\EloquentPromptConfigurationRepository;
use App\Mvp\Communications\Adapters\Outbound\Storage\FlysystemCommunicationCoverAdapter;
use App\Mvp\Communications\Adapters\Primary\Workflow\CommunicationWorkflowTaskHandler;
use App\Mvp\Communications\Application\Listeners\RecordAiOutputRejected;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationCoverDegraded;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationCoverGenerated;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationDeleted;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationDraftApproved;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationDraftDiscarded;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationDraftEdited;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationDraftFavorited;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationDraftUnfavorited;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationRated;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationRegenerationRequested;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationTextGenerated;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationWorkflowCompleted;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationWorkflowStarted;
use App\Mvp\Communications\Application\Listeners\RecordCommunicationWorkflowStartFailed;
use App\Mvp\Communications\Application\Listeners\RecordPromptConfigurationDeleted;
use App\Mvp\Communications\Application\Listeners\RecordPromptConfigurationSaved;
use App\Mvp\Communications\Application\UseCases\CommunicationDraftService;
use App\Mvp\Communications\Application\UseCases\DeleteCommunicationService;
use App\Mvp\Communications\Application\UseCases\DownloadCommunicationCoverService;
use App\Mvp\Communications\Application\UseCases\ExportCommunicationService;
use App\Mvp\Communications\Application\UseCases\FinalizeCommunicationService;
use App\Mvp\Communications\Application\UseCases\GenerateCommunicationCoverService;
use App\Mvp\Communications\Application\UseCases\GenerateCommunicationService;
use App\Mvp\Communications\Application\UseCases\GenerateCommunicationTextService;
use App\Mvp\Communications\Application\UseCases\ListCommunicationsService;
use App\Mvp\Communications\Application\UseCases\PollCommunicationProgressService;
use App\Mvp\Communications\Application\UseCases\PromptConfigurationService;
use App\Mvp\Communications\Application\UseCases\RateCommunicationService;
use App\Mvp\Communications\Application\UseCases\StartCommunicationWorkflowService;
use App\Mvp\Communications\Application\UseCases\UpdateCommunicationCoverService;
use App\Mvp\Communications\Domain\Events\AiOutputRejected;
use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationCoverGenerated;
use App\Mvp\Communications\Domain\Events\CommunicationDeleted;
use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;
use App\Mvp\Communications\Domain\Events\CommunicationDraftEdited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftUnfavorited;
use App\Mvp\Communications\Domain\Events\CommunicationRated;
use App\Mvp\Communications\Domain\Events\CommunicationRegenerationRequested;
use App\Mvp\Communications\Domain\Events\CommunicationTextGenerated;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowCompleted;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;
use App\Mvp\Communications\Domain\Events\PromptConfigurationDeleted;
use App\Mvp\Communications\Domain\Events\PromptConfigurationSaved;
use App\Mvp\Communications\Domain\Ports\Inbound\CommunicationDraftUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\DeleteCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\DownloadCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\ExportCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationTextUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\ListCommunicationsUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\PollCommunicationProgressUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\PromptConfigurationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\RateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\StartCommunicationWorkflowUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\UpdateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationPdfRendererPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\Ports\Outbound\PromptConfigurationRepository;
use App\Mvp\Documents\Adapters\Outbound\Ai\BedrockDocumentAiAdapter;
use App\Mvp\Documents\Adapters\Outbound\Events\LaravelDocumentEventDispatcher;
use App\Mvp\Documents\Adapters\Outbound\Ocr\TextractOcrAdapter;
use App\Mvp\Documents\Adapters\Outbound\Pdf\DompdfSendMessageRenderer;
use App\Mvp\Documents\Adapters\Outbound\Persistence\EloquentDocumentRepository;
use App\Mvp\Documents\Adapters\Outbound\Storage\FlysystemDocumentStorageAdapter;
use App\Mvp\Documents\Adapters\Primary\Workflow\DocumentWorkflowTaskHandler;
use App\Mvp\Documents\Application\Listeners\RecordAiOutputRejected as RecordDocumentAiOutputRejected;
use App\Mvp\Documents\Application\Listeners\RecordDocumentProcessingCompleted;
use App\Mvp\Documents\Application\Listeners\RecordDocumentProcessingFailed;
use App\Mvp\Documents\Application\Listeners\RecordDocumentProcessingStarted;
use App\Mvp\Documents\Application\Listeners\RecordDocumentWorkflowCompleted;
use App\Mvp\Documents\Application\Listeners\RecordDocumentWorkflowStarted;
use App\Mvp\Documents\Application\Listeners\RecordDocumentWorkflowStartFailed;
use App\Mvp\Documents\Application\Listeners\RecordSendMessageExported;
use App\Mvp\Documents\Application\Listeners\RecordSendMessageOverridesCorrected;
use App\Mvp\Documents\Application\Listeners\RecordSubDocumentDeleted;
use App\Mvp\Documents\Application\Listeners\RecordSubDocumentExtractedDataCorrected;
use App\Mvp\Documents\Application\Listeners\RecordSubDocumentFieldsExtracted;
use App\Mvp\Documents\Application\Listeners\RecordSubDocumentManuallyValidated;
use App\Mvp\Documents\Application\Support\OcrRangeReader;
use App\Mvp\Documents\Application\UseCases\DeleteDocumentService;
use App\Mvp\Documents\Application\UseCases\ExtractSubDocumentFieldsService;
use App\Mvp\Documents\Application\UseCases\FinalizeDocumentWorkflowService;
use App\Mvp\Documents\Application\UseCases\ListDocumentsService;
use App\Mvp\Documents\Application\UseCases\PollDocumentProgressService;
use App\Mvp\Documents\Application\UseCases\PreviewDocumentService;
use App\Mvp\Documents\Application\UseCases\ProcessDocumentService;
use App\Mvp\Documents\Application\UseCases\ReviewDocumentService;
use App\Mvp\Documents\Application\UseCases\RunOcrService;
use App\Mvp\Documents\Application\UseCases\SendMessageService;
use App\Mvp\Documents\Application\UseCases\StartDocumentWorkflowService;
use App\Mvp\Documents\Application\UseCases\UploadDocumentService;
use App\Mvp\Documents\Domain\Events\AiOutputRejected as DocumentAiOutputRejected;
use App\Mvp\Documents\Domain\Events\DocumentProcessingCompleted;
use App\Mvp\Documents\Domain\Events\DocumentProcessingFailed;
use App\Mvp\Documents\Domain\Events\DocumentProcessingStarted;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowCompleted;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStarted;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStartFailed;
use App\Mvp\Documents\Domain\Events\SendMessageExported;
use App\Mvp\Documents\Domain\Events\SendMessageOverridesCorrected;
use App\Mvp\Documents\Domain\Events\SubDocumentDeleted;
use App\Mvp\Documents\Domain\Events\SubDocumentExtractedDataCorrected;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;
use App\Mvp\Documents\Domain\Events\SubDocumentManuallyValidated;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ExtractSubDocumentFieldsUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\FinalizeDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ListDocumentsUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\PollDocumentProgressUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\PreviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ProcessDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ReviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\RunOcrUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\SendMessageUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Support\Clock\SystemClock;
use App\Mvp\Support\Identifiers\RandomUuidGenerator;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

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

        // Orologio condiviso (PSR-20): a differenza degli eventi di dominio,
        // il tempo non ha semantica specifica di un dominio, quindi un solo
        // binding serve sia Documents sia Communications (vedi ADR 0010).
        $this->app->singleton(ClockInterface::class, SystemClock::class);

        // Generatore di id univoci condiviso: stessa logica del Clock sopra,
        // nessuno standard PSR esiste per questo quindi l'interfaccia e'
        // definita in App\Mvp\Support\Identifiers (vedi ADR 0010).
        $this->app->singleton(UniqueIdGeneratorPort::class, RandomUuidGenerator::class);

        // --- Dominio Documents: porta -> adapter (vedi ADR 0010) ---
        $this->app->singleton(OcrGatewayPort::class, TextractOcrAdapter::class);
        $this->app->singleton(DocumentAiGatewayPort::class, BedrockDocumentAiAdapter::class);
        $this->app->singleton(DocumentStoragePort::class, FlysystemDocumentStorageAdapter::class);
        $this->app->singleton(DocumentRepository::class, EloquentDocumentRepository::class);
        $this->app->singleton(SendMessageRendererPort::class, DompdfSendMessageRenderer::class);
        $this->app->singleton(DocumentEventDispatcherPort::class, LaravelDocumentEventDispatcher::class);

        // Workflow: porta condivisa fra Documents e Communications.
        $this->app->singleton(WorkflowEnginePort::class, SfnWorkflowEngineAdapter::class);

        // Documents: porta primaria -> caso d'uso applicativo.
        $this->app->bind(UploadDocumentUseCase::class, UploadDocumentService::class);
        $this->app->bind(StartDocumentWorkflowUseCase::class, StartDocumentWorkflowService::class);
        $this->app->bind(ListDocumentsUseCase::class, ListDocumentsService::class);
        $this->app->bind(DeleteDocumentUseCase::class, DeleteDocumentService::class);
        $this->app->bind(RunOcrUseCase::class, RunOcrService::class);
        // Soglia di confidenza risolta qui (confine container) invece che con
        // config() dentro la classe applicativa, cosi' ExtractSubDocumentFieldsService
        // resta istanziabile in un test Pest puro senza bootstrap Laravel
        // (vedi refactory.md, Compito 3 punto 2).
        $this->app->bind(ExtractSubDocumentFieldsUseCase::class, function ($app) {
            return new ExtractSubDocumentFieldsService(
                $app->make(DocumentRepository::class),
                $app->make(DocumentAiGatewayPort::class),
                $app->make(DocumentEventDispatcherPort::class),
                $app->make(LoggerInterface::class),
                $app->make(OcrRangeReader::class),
                max(0, min(100, (int) config('services.bedrock.mvp_confidence_threshold', 80))),
            );
        });
        $this->app->bind(ProcessDocumentUseCase::class, ProcessDocumentService::class);
        $this->app->bind(FinalizeDocumentWorkflowUseCase::class, FinalizeDocumentWorkflowService::class);
        $this->app->bind(ReviewDocumentUseCase::class, ReviewDocumentService::class);
        $this->app->bind(SendMessageUseCase::class, SendMessageService::class);
        $this->app->bind(PreviewDocumentUseCase::class, PreviewDocumentService::class);
        $this->app->bind(PollDocumentProgressUseCase::class, PollDocumentProgressService::class);

        // --- Dominio Communications: porta -> adapter (vedi ADR 0010) ---
        $this->app->singleton(CommunicationAiGatewayPort::class, BedrockCommunicationAiAdapter::class);
        $this->app->singleton(CommunicationPdfRendererPort::class, DompdfCommunicationPdfRenderer::class);
        $this->app->singleton(CommunicationCoverStoragePort::class, FlysystemCommunicationCoverAdapter::class);
        $this->app->singleton(CommunicationRepository::class, EloquentCommunicationRepository::class);
        $this->app->singleton(PromptConfigurationRepository::class, EloquentPromptConfigurationRepository::class);
        $this->app->singleton(CommunicationEventDispatcherPort::class, LaravelCommunicationEventDispatcher::class);

        // Communications: porta primaria -> caso d'uso applicativo.
        $this->app->bind(GenerateCommunicationUseCase::class, GenerateCommunicationService::class);
        $this->app->bind(StartCommunicationWorkflowUseCase::class, StartCommunicationWorkflowService::class);
        $this->app->bind(ListCommunicationsUseCase::class, ListCommunicationsService::class);
        $this->app->bind(PollCommunicationProgressUseCase::class, PollCommunicationProgressService::class);
        $this->app->bind(CommunicationDraftUseCase::class, CommunicationDraftService::class);
        $this->app->bind(DeleteCommunicationUseCase::class, DeleteCommunicationService::class);
        $this->app->bind(RateCommunicationUseCase::class, RateCommunicationService::class);
        $this->app->bind(ExportCommunicationUseCase::class, ExportCommunicationService::class);
        $this->app->bind(PromptConfigurationUseCase::class, PromptConfigurationService::class);
        $this->app->bind(FinalizeCommunicationUseCase::class, FinalizeCommunicationService::class);
        $this->app->bind(DownloadCommunicationCoverUseCase::class, DownloadCommunicationCoverService::class);

        // Il prefisso di storage delle copertine e' l'unico parametro di
        // configurazione di cui questi due casi d'uso hanno bisogno: lo
        // risolviamo qui (confine container) invece che con config() dentro
        // la classe applicativa, cosi' restano istanziabili in un test Pest
        // puro senza bootstrap Laravel (vedi refactory.md, Compito 3 punto 2).
        $this->app->bind(UpdateCommunicationCoverUseCase::class, function ($app) {
            return new UpdateCommunicationCoverService(
                $app->make(CommunicationRepository::class),
                $app->make(CommunicationCoverStoragePort::class),
                $app->make(UniqueIdGeneratorPort::class),
                trim((string) config('mvp.communications.cover_prefix', 'communications/covers'), '/'),
            );
        });
        $this->app->bind(GenerateCommunicationTextUseCase::class, GenerateCommunicationTextService::class);
        $this->app->bind(GenerateCommunicationCoverUseCase::class, function ($app) {
            return new GenerateCommunicationCoverService(
                $app->make(CommunicationRepository::class),
                $app->make(CommunicationCoverStoragePort::class),
                $app->make(CommunicationAiGatewayPort::class),
                $app->make(CommunicationEventDispatcherPort::class),
                $app->make(LoggerInterface::class),
                $app->make(UniqueIdGeneratorPort::class),
                trim((string) config('mvp.communications.cover_prefix', 'communications/covers'), '/'),
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

        // Communications: eventi di dominio (Observer, vedi ADR 0010) -> listener
        // applicativi che registrano audit/metriche. I casi d'uso pubblicano un
        // fatto avvenuto senza sapere chi reagisce; aggiungere una reazione (es.
        // una notifica futura) significa aggiungere un listener, non toccare
        // ogni caso d'uso che genera l'evento.
        Event::listen(CommunicationTextGenerated::class, RecordCommunicationTextGenerated::class);
        Event::listen(AiOutputRejected::class, RecordAiOutputRejected::class);
        Event::listen(CommunicationCoverGenerated::class, RecordCommunicationCoverGenerated::class);
        Event::listen(CommunicationCoverDegraded::class, RecordCommunicationCoverDegraded::class);
        Event::listen(CommunicationWorkflowCompleted::class, RecordCommunicationWorkflowCompleted::class);
        Event::listen(CommunicationDraftFavorited::class, RecordCommunicationDraftFavorited::class);
        Event::listen(CommunicationDraftUnfavorited::class, RecordCommunicationDraftUnfavorited::class);
        Event::listen(CommunicationDraftEdited::class, RecordCommunicationDraftEdited::class);
        Event::listen(CommunicationDraftApproved::class, RecordCommunicationDraftApproved::class);
        Event::listen(CommunicationDraftDiscarded::class, RecordCommunicationDraftDiscarded::class);
        Event::listen(CommunicationDeleted::class, RecordCommunicationDeleted::class);
        Event::listen(CommunicationRated::class, RecordCommunicationRated::class);
        Event::listen(CommunicationRegenerationRequested::class, RecordCommunicationRegenerationRequested::class);
        Event::listen(CommunicationWorkflowStarted::class, RecordCommunicationWorkflowStarted::class);
        Event::listen(CommunicationWorkflowStartFailed::class, RecordCommunicationWorkflowStartFailed::class);
        Event::listen(PromptConfigurationSaved::class, RecordPromptConfigurationSaved::class);
        Event::listen(PromptConfigurationDeleted::class, RecordPromptConfigurationDeleted::class);

        // Documents: stesso trattamento Observer di Communications, porta ed
        // eventi separati (non condivisi) perche' gli eventi sono specifici
        // del dominio, come le porte di persistenza (vedi ADR 0010).
        Event::listen(DocumentProcessingStarted::class, RecordDocumentProcessingStarted::class);
        Event::listen(DocumentProcessingCompleted::class, RecordDocumentProcessingCompleted::class);
        Event::listen(DocumentProcessingFailed::class, RecordDocumentProcessingFailed::class);
        Event::listen(SubDocumentFieldsExtracted::class, RecordSubDocumentFieldsExtracted::class);
        Event::listen(DocumentAiOutputRejected::class, RecordDocumentAiOutputRejected::class);
        Event::listen(DocumentWorkflowCompleted::class, RecordDocumentWorkflowCompleted::class);
        Event::listen(DocumentWorkflowStarted::class, RecordDocumentWorkflowStarted::class);
        Event::listen(DocumentWorkflowStartFailed::class, RecordDocumentWorkflowStartFailed::class);
        Event::listen(SubDocumentDeleted::class, RecordSubDocumentDeleted::class);
        Event::listen(SubDocumentExtractedDataCorrected::class, RecordSubDocumentExtractedDataCorrected::class);
        Event::listen(SubDocumentManuallyValidated::class, RecordSubDocumentManuallyValidated::class);
        Event::listen(SendMessageExported::class, RecordSendMessageExported::class);
        Event::listen(SendMessageOverridesCorrected::class, RecordSendMessageOverridesCorrected::class);
    }
}
