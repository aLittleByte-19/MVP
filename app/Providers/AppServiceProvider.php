<?php

namespace App\Providers;

use App\Services\BedrockService;
use App\Services\DocumentProcessingService;
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
            return new BedrockRuntimeClient([
                'version' => 'latest',
                'region' => config('services.bedrock.region'),
                'credentials' => [
                    'key' => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                    'token' => env('AWS_SESSION_TOKEN'),
                ],
            ]);
        });

        $this->app->singleton(BedrockService::class, function ($app) {
            return new BedrockService(
                $app->make(BedrockRuntimeClient::class),
                config('services.bedrock.model_id'),
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
