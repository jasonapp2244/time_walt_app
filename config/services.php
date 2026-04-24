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

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'auto_transfer_enabled' => env('STRIPE_AUTO_TRANSFER', false),

        // Supported currencies for payment intents
        'supported_currencies' => [
            'usd' => 'USD',
            'eur' => 'EUR',
            'gbp' => 'GBP',
        ],

        // Stripe Connect OAuth Return URL (set in .env file)
        'connect_return_url' => env('STRIPE_CONNECT_RETURN_URL', 'https://time-vault.devonlinetestserver.com/api/stripe/connect/return'),

        // Payment Intent Return URL (where Stripe redirects after payment) - set in .env file
        'payment_return_url' => env('STRIPE_PAYMENT_RETURN_URL', 'https://time-vault.devonlinetestserver.com/api/stripe/payment/return'),

        // Frontend URLs (set in .env file)
        'payment_success_url' => env('STRIPE_PAYMENT_SUCCESS_URL', 'https://time-vault.devonlinetestserver.com/payment/success'),
        'payment_failed_url' => env('STRIPE_PAYMENT_FAILED_URL', 'https://time-vault.devonlinetestserver.com/payment/failed'),

        // Platform URL for Stripe Custom Connect business_profile (must be https, not localhost)
        'platform_url' => env('STRIPE_PLATFORM_URL', 'https://timevault.app'),
    ],

];
