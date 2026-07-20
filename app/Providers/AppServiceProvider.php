<?php

namespace App\Providers;

use App\Copilot\Ai\AiOutputValidator;
use App\Copilot\Ai\BedrockService;
use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Services\DocumentProcessingService;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Ocr\Services\TextractService;
use App\Copilot\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Sfn\SfnClient;
use Aws\Sqs\SqsClient;
use Aws\Textract\TextractClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BedrockRuntimeClient::class, function () {
            $config = [
                'version' => 'latest',
                'region' => config('services.bedrock.region'),
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
        });

        $this->app->singleton(BedrockService::class, function ($app) {
            return new BedrockService(
                $app->make(BedrockRuntimeClient::class),
                config('services.bedrock.model_id'),
                config('services.bedrock.image_model_id'),
                $app->make(AiOutputValidator::class),
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
    }
}
