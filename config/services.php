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

    'codex' => [
        'binary' => env('CODEX_BINARY', '/home/u105894521/.local/bin/codex'),
        'home' => env('CODEX_HOME', '/home/u105894521/.codex'),
        'auth_file' => env('CODEX_AUTH_FILE', '/home/u105894521/.codex/auth.json'),
        'model' => env('CODEX_MODEL', 'gpt-6.2-sol'),
        'timeout' => (int) env('CODEX_TIMEOUT', 55),
    ],

];
