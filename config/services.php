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

    // These are only the *defaults* this install starts with — an admin can
    // view and change every one of them live from Admin > Settings >
    // Payments (see PaymentGatewayService::platformSetting(), which checks
    // the database first and only falls back to these .env values when
    // nothing's been set there yet). Still worth setting here for a fresh
    // install, or as the value the admin panel falls back to if ever cleared.
    'mpesa' => [
        // 'sandbox' or 'production'. Sandbox talks to Safaricom's test environment
        // and never moves real money.
        'env' => env('MPESA_ENV', 'sandbox'),
        // Kats Agencies' own Daraja app and paybill — every tenant STK push
        // settles here, into Kats' own account, not the landlord's. Landlords
        // never register their own Daraja app or shortcode (see
        // PaymentGatewayService); Kats disburses collected rent out to each
        // landlord separately, on its own schedule.
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        // Must be a publicly reachable HTTPS URL Safaricom can call back — localhost will
        // never receive it. Use a tunnel (e.g. ngrok) in local dev. Give the base path only
        // (e.g. https://domain.com/mpesa/callback) — the secret below is appended automatically.
        'callback_url' => env('MPESA_CALLBACK_URL'),
        // A long random string known only to this app, appended to the callback URL as a path
        // segment so the webhook can't be guessed or replayed by anyone who hasn't seen it.
        // Daraja callbacks carry no signature of their own, so this is the only thing standing
        // between the endpoint and a forged "payment succeeded" POST. Generate one with
        // `php artisan tinker --execute="echo Str::random(40);"` and never reuse it elsewhere.
        'callback_secret' => env('MPESA_CALLBACK_SECRET'),
    ],

    // Free tier: 5,000 requests/day, no credit card. Used only server-side
    // (see App\Http\Controllers\GeocodingController) — the key never
    // reaches the browser. Sign up at locationiq.com to get a key; the
    // address autocomplete field degrades to a plain text input when this
    // is left unset, rather than erroring.
    'locationiq' => [
        'key' => env('LOCATIONIQ_KEY'),
    ],

    'africastalking' => [
        // From your app in the Africa's Talking dashboard (africastalking.com).
        // Sandbox credentials only ever reach their simulator, never a real
        // phone — switch 'sandbox' to false and use your live API key once
        // you've bought real SMS credit.
        'sandbox' => env('AFRICASTALKING_SANDBOX', true),
        'username' => env('AFRICASTALKING_USERNAME', 'sandbox'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        // Optional — a registered Sender ID/shortcode. Omitted, texts arrive
        // from Africa's Talking's shared/generic sender instead.
        'sender_id' => env('AFRICASTALKING_SENDER_ID'),
    ],

];
