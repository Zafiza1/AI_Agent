<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | GitHub integration. The GitHub App is registered once by the platform operator;
    | organizations install it. Personal access tokens work without an App.
    | See docs/github.md.
    */
    'github' => [
        'api_url' => rtrim(env('GITHUB_API_URL', 'https://api.github.com'), '/'),
        'web_url' => rtrim(env('GITHUB_WEB_URL', 'https://github.com'), '/'),
        'timeout' => (int) env('GITHUB_TIMEOUT', 15),
        'app' => [
            'id' => env('GITHUB_APP_ID'),
            'slug' => env('GITHUB_APP_SLUG'),
            'client_id' => env('GITHUB_APP_CLIENT_ID'),
            'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
            // PEM contents (newlines may be written as "\n") or a path to the .pem file.
            'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
            'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
            'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
        ],
        // Public base URL GitHub delivers repository webhooks to (defaults to APP_URL).
        'webhook_base_url' => env('GITHUB_WEBHOOK_BASE_URL'),
    ],

    'agent' => [
        'url' => env('AGENT_ENGINE_URL', 'http://agent:8001'),
        'timeout' => (int) env('AGENT_ENGINE_TIMEOUT', 3),
    ],

];
