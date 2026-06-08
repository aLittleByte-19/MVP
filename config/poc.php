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
];
