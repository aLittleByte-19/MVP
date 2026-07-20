<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bedrock' => [
        'model_id' => env('BEDROCK_MODEL_ID'),
        'image_model_id' => env('BEDROCK_IMAGE_MODEL_ID', env('MVP_BEDROCK_IMAGE_MODEL_ID')),
        'region' => env('BEDROCK_REGION', env('AWS_DEFAULT_REGION', 'eu-north-1')),
        'endpoint' => env('BEDROCK_ENDPOINT') === 'not-configured' ? null : env('BEDROCK_ENDPOINT'),
        // Shared real-AWS credentials (same set used by real S3 and Textract).
        // Left empty in LocalStack mode, where the SDK default chain applies.
        'credentials' => [
            'key' => env('AWS_REAL_ACCESS_KEY_ID'),
            'secret' => env('AWS_REAL_SECRET_ACCESS_KEY'),
            'token' => env('AWS_REAL_SESSION_TOKEN'),
        ],
        'mvp_confidence_threshold' => (int) env('MVP_CONFIDENCE_THRESHOLD', 80),
    ],

    'workflow' => [
        'region' => env('AWS_DEFAULT_REGION', 'eu-north-1'),
        'endpoint' => env('STEPFUNCTIONS_ENDPOINT', env('AWS_ENDPOINT')),
        'state_machine_arn' => env('DOCUMENT_PIPELINE_STATE_MACHINE_ARN'),
        'task_queue_url' => env('DOCUMENT_PIPELINE_TASK_QUEUE_URL'),
        'dlq_queue_url' => env('SQS_DLQ_URL'),
    ],

    'sqs' => [
        'region' => env('AWS_DEFAULT_REGION', 'eu-north-1'),
        'endpoint' => env('SQS_ENDPOINT'),
        'queue_url' => env('DOCUMENT_PIPELINE_TASK_QUEUE_URL'),
        'dlq_queue_url' => env('SQS_DLQ_URL'),
    ],

    'textract' => [
        'enabled' => (bool) env('TEXTRACT_ENABLED', false),
        'region' => env('TEXTRACT_REGION', env('TEXTRACT_AWS_REGION', env('AWS_REAL_REGION', env('AWS_DEFAULT_REGION', 'eu-central-1')))),
        's3_bucket' => env('AWS_REAL_S3_BUCKET', env('TEXTRACT_S3_BUCKET')),
        'poll_interval_seconds' => (int) env('TEXTRACT_POLL_INTERVAL_SECONDS', 5),
        'timeout_seconds' => (int) env('TEXTRACT_TIMEOUT_SECONDS', 300),
        'max_pages' => env('TEXTRACT_MAX_PAGES'),
        'max_bytes' => env('TEXTRACT_MAX_BYTES'),
        'credentials' => [
            'key' => env('AWS_REAL_ACCESS_KEY_ID'),
            'secret' => env('AWS_REAL_SECRET_ACCESS_KEY'),
            'token' => env('AWS_REAL_SESSION_TOKEN'),
        ],
        'sns_topic_arn' => env('TEXTRACT_SNS_TOPIC_ARN'),
        'role_arn' => env('TEXTRACT_ROLE_ARN'),
    ],

];
