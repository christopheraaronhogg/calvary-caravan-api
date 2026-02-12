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

    'nominatim' => [
        'enabled' => env('NOMINATIM_ENABLED', true),
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org/reverse'),
        'timeout_seconds' => env('NOMINATIM_TIMEOUT_SECONDS', 2.5),
        'cache_minutes' => env('NOMINATIM_CACHE_MINUTES', 90),
        'accept_language' => env('NOMINATIM_ACCEPT_LANGUAGE', 'en'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'CalvaryCaravan/1.0 (support@calvarycaravan.on-forge.com)'),
        'allow_during_tests' => env('NOMINATIM_ALLOW_DURING_TESTS', false),
    ],

];
