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

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
        // Small, fast model for the closed-set label re-ranker (Phase 4).
        'label_model' => env('ANTHROPIC_LABEL_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    'cashier' => [
        'api_key'     => env('CASHIER_API_KEY', ''),
        'url'         => env('CASHIER_API_URL', 'https://puptec.ojt-ims-bsit.net/api/verify-payment'),
        // single_use: when true, each OR number can only be used once.
        // SECURE-BY-DEFAULT: defaults to true. A student/alumni's OR is
        // a real payment; the default posture must be "one OR funds one
        // request" without anyone having to remember to turn that on.
        // Set CASHIER_SINGLE_USE=false only in local dev/testing .env
        // files where the same fixture OR number needs to be reused
        // across runs — never in a staging or production .env.
        'single_use'  => env('CASHIER_SINGLE_USE', true),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];