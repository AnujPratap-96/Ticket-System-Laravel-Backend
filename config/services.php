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

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'max_bytes' => 10 * 1024 * 1024,
        'max_files' => 5,
        'allowed_formats' => 'jpg,jpeg,png,gif,webp,pdf,txt,log,zip',
    ],

    // OpenAI-compatible chat API. Defaults to Groq; any compatible provider works by changing the URL/model.
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:support@example.com'),
        // We only ever POST to the browsers' own push services. Anything else is refused (the address comes from the client).
        'allowed_hosts' => ['googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'],
    ],

    'webhooks' => [
        // Plain http:// is refused unless you explicitly allow it (local development only).
        'allow_http' => (bool) env('WEBHOOKS_ALLOW_HTTP', false),
    ],

    'ai' => [
        'base_url' => env('AI_BASE_URL', 'https://api.groq.com/openai/v1'),
        'api_key' => env('GROQ_API_KEY', env('GROK_API_KEY', env('AI_API_KEY'))),
        'model' => env('AI_MODEL', 'qwen/qwen3.8-27b'),
        'timeout' => 20,
        // Hard ceiling on provider calls per UTC day (free tiers are small); 0 = unlimited.
        'daily_limit' => (int) env('AI_DAILY_LIMIT', 800),
    ],

    // Lets an external pinger (cron-job.org, GitHub Actions ...) drive the scheduler and queue when no worker/cron service exists.
    'cron' => [
        'secret' => env('CRON_SECRET'),
    ],

    'inbound_email' => [
        // Shared secret the mail provider sends in the X-Webhook-Secret header (or ?secret=).
        'secret' => env('INBOUND_EMAIL_SECRET'),
        // Where brand-new emails from known customers are filed (department slug).
        'default_department' => env('INBOUND_EMAIL_DEFAULT_DEPARTMENT'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
