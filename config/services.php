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

    // "Pay online": Xendit is used when its key is set (PAYMENT_GATEWAY=paymongo prefers PayMongo when both are set).
    'online' => [
        'gateway' => env('PAYMENT_GATEWAY', ''),
    ],

    // Xendit Invoices (https://developers.xendit.co). Keys: dashboard.xendit.co → Settings → API keys.
    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        // Settings → Webhooks → "Webhook verification token"; sent with every callback as x-callback-token.
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        // Empty = every channel activated in the dashboard. E.g. GCASH,PAYMAYA,GRABPAY,QRPH,CREDIT_CARD
        'methods' => array_values(array_filter(explode(',', (string) env('XENDIT_METHODS', '')))),
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
    ],

    // Online payments (https://developers.paymongo.com). Leave the secret key empty to hide "Pay online".
    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        // Practice mode: a built-in fake checkout (no account, no real money). Never works in production.
        'fake' => (bool) env('PAYMONGO_FAKE', false),
        'methods' => array_values(array_filter(explode(',', (string) env('PAYMONGO_METHODS', 'gcash,paymaya,card,qrph')))),
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
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

];
