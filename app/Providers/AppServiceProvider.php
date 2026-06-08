<?php

namespace App\Providers;

use App\Poc\Services\AuditLogger;
use App\Poc\Services\BedrockService;
use App\Poc\Services\DocumentProcessingService;
use Aws\BedrockRuntime\BedrockRuntimeClient;
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

            if (filled(config('services.bedrock.endpoint'))) {
                $config['endpoint'] = config('services.bedrock.endpoint');
            }

            return new BedrockRuntimeClient($config);
        });

        $this->app->singleton(BedrockService::class, function ($app) {
            return new BedrockService(
                $app->make(BedrockRuntimeClient::class),
                config('services.bedrock.model_id'),
            );
        });

        $this->app->singleton(DocumentProcessingService::class, function ($app) {
            return new DocumentProcessingService(
                $app->make(BedrockService::class),
                $app->make(AuditLogger::class),
            );
        });
    }
}
