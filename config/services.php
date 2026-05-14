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
        'enabled' => env('BEDROCK_ENABLED', false),
        'model_id' => env('BEDROCK_MODEL_ID'),
        'region' => env('AWS_DEFAULT_REGION', 'eu-north-1'),
        'credentials' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'token' => env('AWS_SESSION_TOKEN'),
        ],
        'poc_confidence_threshold' => (int) env('POC_CONFIDENCE_THRESHOLD', 80),
    ],

    'textract' => [
        'enabled' => env('TEXTRACT_ENABLED', false),
        'region' => env('TEXTRACT_AWS_REGION', env('AWS_DEFAULT_REGION', 'eu-central-1')),
        's3_bucket' => env('TEXTRACT_S3_BUCKET'),
        'sns_topic_arn' => env('TEXTRACT_SNS_TOPIC_ARN'),
        'role_arn' => env('TEXTRACT_ROLE_ARN'),
    ],

    'documents' => [
        'ocr_driver' => env('DOCUMENT_OCR_DRIVER', 'local'),
        'classifier_driver' => env('DOCUMENT_CLASSIFIER_DRIVER', 'fake'),
    ],

];
