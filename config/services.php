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

    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL'),
        'base_url' => env('N8N_BASE_URL'),
        'api_key' => env('N8N_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'openrouter/free'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'low_credits_threshold' => (int) env('TELEGRAM_LOW_CREDITS_THRESHOLD', 10),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    ],

    'webhooks' => [
        'meta_secret' => env('WEBHOOKS_META_SECRET'),
        'tiktok_secret' => env('WEBHOOKS_TIKTOK_SECRET'),
        'google_secret' => env('WEBHOOKS_GOOGLE_SECRET'),
        'snapchat_secret' => env('WEBHOOKS_SNAPCHAT_SECRET'),
        'universal_secret' => env('WEBHOOKS_UNIVERSAL_SECRET'),
        'website_secret' => env('WEBHOOKS_WEBSITE_SECRET'),
    ],

];
