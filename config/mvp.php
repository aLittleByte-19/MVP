<?php

return [
    'identity' => [
        'mode' => env('MVP_IDENTITY_MODE', 'local'),
        'local' => [
            'id' => env('MVP_LOCAL_USER_ID', 'mvp-local-user'),
            'email' => env('MVP_LOCAL_USER_EMAIL', 'operator@alittlebyte.local'),
            'name' => env('MVP_LOCAL_USER_NAME', 'Alittlebyte Operator'),
            'tenant_id' => env('MVP_LOCAL_TENANT_ID', 'mvp-local-tenant'),
            'roles' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('MVP_LOCAL_ROLES', 'mvp-operator'))
            ))),
        ],
        'trusted_headers' => [
            'id' => 'X-Mvp-User-Id',
            'email' => 'X-Mvp-User-Email',
            'name' => 'X-Mvp-User-Name',
            'tenant_id' => 'X-Mvp-Tenant-Id',
            'roles' => 'X-Mvp-Roles',
        ],
    ],

    'authorization' => [
        'roles' => ['mvp-operator', 'mvp-admin'],
    ],

    'documents' => [
        'storage_disk' => env('MVP_DOCUMENT_DISK', env('FILESYSTEM_DISK', 's3')),
    ],

    'document_limits' => [
        'max_upload_mb' => (int) env('MVP_MAX_UPLOAD_MB', env('DOCUMENT_MAX_UPLOAD_MB', 20)),
        'max_pdf_pages' => (int) env('MVP_MAX_PDF_PAGES', 50),
        // Path esplicito del binario qpdf; vuoto = autodetect nei path standard.
        'qpdf_binary' => env('MVP_QPDF_BINARY', ''),
        'processing_timeout_seconds' => (int) env('MVP_PROCESSING_TIMEOUT_SECONDS', 600),
        'textract_timeout_seconds' => (int) env('TEXTRACT_TIMEOUT_SECONDS', 300),
    ],

    'workflow' => [
        'driver' => env('WORKFLOW_DRIVER', 'localstack'),
        'poll_wait_seconds' => (int) env('MVP_WORKFLOW_SQS_WAIT_SECONDS', 10),
        'max_messages' => (int) env('MVP_WORKFLOW_SQS_MAX_MESSAGES', 5),
        // Deve restare sotto il piu' basso HeartbeatSeconds dell'ASL (90s).
        'heartbeat_interval_seconds' => (int) env('MVP_WORKFLOW_HEARTBEAT_SECONDS', 30),
        // Oltre questa eta' un task running viene considerato orfano e
        // riconquistabile: allineato al visibility timeout SQS (900s).
        'running_claim_ttl_seconds' => (int) env('MVP_WORKFLOW_CLAIM_TTL_SECONDS', 900),
    ],
];
