<?php

/*
 * TimeVault admin analytics: Microsoft Clarity (behaviour) + Firebase / GA4
 * (business events). Secrets come from .env only; never commit them.
 * Setup: docs/admin-analytics.md.
 */

return [

    'clarity' => [
        // Clarity → Settings → Data Export → Generate new API token.
        'token' => env('CLARITY_API_TOKEN'),
        'project_id' => env('CLARITY_PROJECT_ID', 'z8isutv2au'),
        'endpoint' => 'https://www.clarity.ms/export-data/api/v1/project-live-insights',

        // One API call per entry, once a day. '' is the overall (no breakdown)
        // call. Clarity allows 10 calls per project per UTC day and keeps only
        // the last 3 days, which is why analytics:sync-clarity stores a daily
        // snapshot.
        'dimensions' => ['', 'OS', 'Device', 'Country/Region'],

        // Hard stop below Clarity's limit of 10, leaving room for a manual
        // `php artisan analytics:sync-clarity --force`.
        'daily_call_budget' => 8,
    ],

    'ga4' => [
        // Firebase console → Project settings → Integrations → Google
        // Analytics → the linked property's numeric ID.
        'property_id' => env('GA4_PROPERTY_ID'),

        // Service-account JSON key with "Viewer" access on that GA4 property.
        // Keep the file outside the web root and out of git.
        'credentials' => env('GA4_CREDENTIALS_PATH') ?: storage_path('app/private/ga4-service-account.json'),

        'cache_minutes' => (int) env('GA4_CACHE_MINUTES', 60),

        // Set to true after registering `reason` as an event-scoped custom
        // dimension in GA4 (Admin → Custom definitions). Enables the
        // "Why things fail" table.
        'reason_dimension' => (bool) env('GA4_REASON_DIMENSION', false),
    ],

    // The 23 Firebase events the app sends (docs/analytics/tracking-plan.md in
    // the app repo). Keep in sync with lib/data/analytics/analytics_service.dart.
    'event_groups' => [
        'Authentication' => ['sign_up', 'sign_up_failed', 'otp_verification', 'login', 'login_failed'],
        'Lock' => ['lock_started', 'lock_created', 'lock_failed'],
        'Bank verification' => ['bank_added', 'kyc_started', 'kyc_returned'],
        'Checkout & payments' => ['begin_checkout', 'checkout_cancelled', 'checkout_failed', 'payment_failed'],
        'Withdrawals' => ['withdraw_started', 'withdraw_blocked', 'withdraw_completed', 'withdraw_failed'],
        'Subscription & paywall' => ['paywall_shown', 'plan_selected', 'purchase', 'subscription_cancelled'],
    ],

    'funnels' => [
        'Lock funds' => ['lock_started', 'lock_created'],
        'Withdraw' => ['withdraw_started', 'withdraw_completed'],
        'Subscribe' => ['paywall_shown', 'plan_selected', 'purchase'],
        'Add bank' => ['kyc_started', 'bank_added'],
    ],

    // Events whose `value` param is money (USD); summed as GA4 eventValue.
    'money_events' => [
        'Locked' => 'lock_created',
        'Withdrawn' => 'withdraw_completed',
        'Subscription revenue' => 'purchase',
    ],
];
