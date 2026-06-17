<?php

return [
    'service' => [
        'namespace' => env('OTEL_SERVICE_NAMESPACE', 'alittlebyte'),
        'name' => env('OTEL_SERVICE_NAME', 'poc-document-pipeline'),
        'version' => env('POC_RELEASE_VERSION', 'local'),
        'environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'local')),
    ],

    'metrics' => [
        'enabled' => (bool) env('POC_METRICS_ENABLED', true),
        'storage_path' => env('POC_METRICS_STORAGE_PATH', 'app/private/observability/metrics.json'),
        'http_duration_buckets' => [
            0.005,
            0.01,
            0.025,
            0.05,
            0.1,
            0.25,
            0.5,
            1,
            2.5,
            5,
            10,
        ],
    ],
];
