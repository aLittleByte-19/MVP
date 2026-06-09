<?php

return [
    'identity' => [
        'mode' => env('POC_IDENTITY_MODE', 'local'),
        'local' => [
            'id' => env('POC_LOCAL_USER_ID', 'poc-local-user'),
            'email' => env('POC_LOCAL_USER_EMAIL', 'operator@alittlebyte.local'),
            'name' => env('POC_LOCAL_USER_NAME', 'Alittlebyte Operator'),
            'tenant_id' => env('POC_LOCAL_TENANT_ID', 'poc-local-tenant'),
            'roles' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('POC_LOCAL_ROLES', 'poc-operator'))
            ))),
        ],
        'trusted_headers' => [
            'id' => 'X-Poc-User-Id',
            'email' => 'X-Poc-User-Email',
            'name' => 'X-Poc-User-Name',
            'tenant_id' => 'X-Poc-Tenant-Id',
            'roles' => 'X-Poc-Roles',
        ],
    ],

    'authorization' => [
        'roles' => ['poc-operator', 'poc-admin'],
    ],

    'documents' => [
        'storage_disk' => env('POC_DOCUMENT_DISK', env('FILESYSTEM_DISK', 's3')),
    ],

    'document_limits' => [
        'max_upload_mb' => (int) env('POC_MAX_UPLOAD_MB', env('DOCUMENT_MAX_UPLOAD_MB', 20)),
        'max_pdf_pages' => (int) env('POC_MAX_PDF_PAGES', 50),
        'processing_timeout_seconds' => (int) env('POC_PROCESSING_TIMEOUT_SECONDS', 600),
        'textract_timeout_seconds' => (int) env('TEXTRACT_TIMEOUT_SECONDS', 300),
    ],

    'workflow' => [
        'driver' => env('WORKFLOW_DRIVER', 'localstack'),
        'poll_wait_seconds' => (int) env('POC_WORKFLOW_SQS_WAIT_SECONDS', 10),
        'max_messages' => (int) env('POC_WORKFLOW_SQS_MAX_MESSAGES', 5),
    ],
];
