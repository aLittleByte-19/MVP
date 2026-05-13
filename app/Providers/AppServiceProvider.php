<?php

namespace App\Providers;

use App\Poc\Services\BedrockService;
use App\Poc\Services\DocumentProcessingService;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BedrockRuntimeClient::class, function () {
            $credentials = config('services.bedrock.credentials');

            $config = [
                'version' => 'latest',
                'region' => config('services.bedrock.region'),
                'http' => [
                    'timeout' => 300,
                    'connect_timeout' => 15,
                ],
            ];

            if (filled($credentials['key'] ?? null) && filled($credentials['secret'] ?? null)) {
                $config['credentials'] = array_filter($credentials, filled(...));
            }

            return new BedrockRuntimeClient($config);
        });

        $this->app->singleton(BedrockService::class, function ($app) {
            $bedrockEnabled = (bool) config('services.bedrock.enabled');

            return new BedrockService(
                $bedrockEnabled ? $app->make(BedrockRuntimeClient::class) : null,
                config('services.bedrock.model_id'),
                $bedrockEnabled,
            );
        });

        $this->app->singleton(DocumentProcessingService::class, function ($app) {
            return new DocumentProcessingService($app->make(BedrockService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
