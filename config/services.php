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

    'mpesa' => [
        // 'sandbox' or 'production'. Sandbox talks to Safaricom's test environment
        // and never moves real money.
        'env' => env('MPESA_ENV', 'sandbox'),
        // From your app in the Daraja developer portal (developer.safaricom.co.ke) — not shareable, keep in .env only.
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        // Safaricom's published sandbox test shortcode/passkey — safe defaults for sandbox testing.
        // Replace both with your own Till/Paybill shortcode and passkey before going to production.
        'shortcode' => env('MPESA_SHORTCODE', '174379'),
        'passkey' => env('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'),
        // Must be a publicly reachable HTTPS URL Safaricom can call back — localhost will
        // never receive it. Use a tunnel (e.g. ngrok) in local dev.
        'callback_url' => env('MPESA_CALLBACK_URL'),
    ],

];
