<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;

/**
 * Post-deploy check of the settings the app cannot run correctly without:
 * environment, Stripe keys, the webhook endpoint, plan prices, the Stripe
 * Dashboard settings the API exposes, and mail.
 * Reads only; changes nothing. Exits non-zero when anything FAILs, so
 * deploy.sh can point at it.
 */
class DeployCheck extends Command
{
    protected $signature = 'deploy:check';

    protected $description = 'Check environment, Stripe, webhook, plans, Dashboard and mail settings after a deploy (read-only)';

    /** Events WebhookService handles; the endpoint must send all of them. */
    public const WEBHOOK_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
    ];

    /** Keys the app stopped reading; harmless, but they can be removed from .env. */
    private const OBSOLETE_ENV = [
        'STRIPE_CONNECT_RETURN_URL',
        'STRIPE_PAYMENT_RETURN_URL',
        'STRIPE_PAYMENT_SUCCESS_URL',
        'STRIPE_PAYMENT_FAILED_URL',
    ];

    private int $failures = 0;

    private int $warnings = 0;

    private ?\Stripe\Account $account = null;

    public function handle(): int
    {
        $this->checkEnvironment();
        $stripeReady = $this->checkStripeKeys();
        if ($stripeReady) {
            $this->checkWebhook();
            $this->checkPlans();
            $this->checkDashboardSettings();
        }
        $this->checkMail();

        $this->newLine();
        $summary = "{$this->failures} problem(s), {$this->warnings} warning(s)";
        $this->failures === 0 ? $this->info("deploy:check OK - {$summary}") : $this->error("deploy:check - {$summary}");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkEnvironment(): void
    {
        $this->section('Environment');
        $this->expect(app()->environment('production'), 'APP_ENV=production', 'APP_ENV is "'.app()->environment().'", expected production');
        $this->expect(! config('app.debug'), 'APP_DEBUG=false', 'APP_DEBUG is true - error pages show stack traces to the public');

        $url = (string) config('app.url');
        $this->expect(str_starts_with($url, 'https://'), "APP_URL={$url}", "APP_URL is \"{$url}\" - expected the public https address");

        // Read the file itself: with config cached (as on the server) env() returns null.
        $envFile = (string) @file_get_contents(app()->environmentFilePath());
        $leftover = array_values(array_filter(self::OBSOLETE_ENV, fn (string $key) => preg_match('/^\s*'.$key.'\s*=/m', $envFile) === 1));
        if ($leftover !== []) {
            $this->warnLine('no longer used, can be removed from .env: '.implode(', ', $leftover));
        }
    }

    private function checkStripeKeys(): bool
    {
        $this->section('Stripe keys');
        $secret = (string) config('services.stripe.secret');
        $publishable = (string) config('services.stripe.publishable_key');

        if (! $this->expect(preg_match('/^sk_(test|live)_/', $secret) === 1, 'STRIPE_SECRET set', 'STRIPE_SECRET is missing or not an sk_ key (an rk_ key cannot manage Connect accounts)')) {
            return false;
        }
        $mode = str_starts_with($secret, 'sk_live_') ? 'live' : 'test';
        $this->expect(str_starts_with($publishable, "pk_{$mode}_"), "STRIPE_PUBLISHABLE_KEY is a {$mode} key", "STRIPE_PUBLISHABLE_KEY must be a pk_{$mode}_ key to match STRIPE_SECRET");
        $this->expect(str_starts_with((string) config('services.stripe.webhook_secret'), 'whsec_'), 'STRIPE_WEBHOOK_SECRET set', 'STRIPE_WEBHOOK_SECRET is missing or not a whsec_ value');

        try {
            \Stripe\Stripe::setApiKey($secret);
            $account = \Stripe\Account::retrieve();
        } catch (\Throwable $e) {
            $this->failLine('Stripe rejected STRIPE_SECRET: '.$e->getMessage());

            return false;
        }
        $this->account = $account;
        $this->okLine("Stripe account {$account->id} ({$mode} mode)");
        if ($mode === 'live') {
            $this->expect((bool) $account->charges_enabled && (bool) $account->payouts_enabled, 'live account can take payments and pay out', 'the live Stripe account is not fully activated (charges or payouts disabled)');
        }

        return true;
    }

    private function checkWebhook(): void
    {
        $this->section('Stripe webhook');
        $url = rtrim((string) config('app.url'), '/').'/api/stripe/webhook';

        try {
            $endpoints = \Stripe\WebhookEndpoint::all(['limit' => 100])->data;
        } catch (\Throwable $e) {
            $this->failLine('could not list webhook endpoints: '.$e->getMessage());

            return;
        }

        $endpoint = collect($endpoints)->first(fn ($e) => rtrim($e->url, '/') === $url);
        if (! $this->expect($endpoint !== null, "endpoint {$url} exists", "no webhook endpoint for {$url} in this Stripe account - create it in Dashboard > Developers > Webhooks")) {
            return;
        }
        $this->expect($endpoint->status === 'enabled', 'endpoint enabled', "endpoint status is {$endpoint->status}");

        $events = $endpoint->enabled_events;
        $missing = in_array('*', $events, true) ? [] : array_values(array_diff(self::WEBHOOK_EVENTS, $events));
        $this->expect($missing === [], 'all '.count(self::WEBHOOK_EVENTS).' events selected', 'endpoint is missing events: '.implode(', ', $missing));
        $this->line('       the signing secret can only be confirmed by a real delivery: make one deposit and look for HTTP 200 on this endpoint');
    }

    private function checkPlans(): void
    {
        $this->section('Subscription plans');
        $plans = SubscriptionPlan::available()->get();
        if (! $this->expect($plans->isNotEmpty(), $plans->count().' plan(s) available', 'no plan is linked to a Stripe price - run: php artisan db:seed --class=SubscriptionPlanSeeder --force')) {
            return;
        }

        foreach ($plans as $plan) {
            try {
                $price = \Stripe\Price::retrieve($plan->stripe_price_id);
                $amountMatches = (int) $price->unit_amount === (int) round($plan->price * 100);
                $this->expect($price->active && $amountMatches, "{$plan->name}: \${$plan->price} price is active in this account",
                    "{$plan->name}: Stripe price ".($price->active ? 'amount differs from the plan' : 'is archived').' - run the plan seeder');
            } catch (\Throwable) {
                $this->failLine("{$plan->name}: price not found in this Stripe account - run: php artisan db:seed --class=SubscriptionPlanSeeder --force");
            }
        }
    }

    /**
     * The Dashboard settings Stripe exposes through the API. The rest cannot
     * be read, so they are printed as "check" lines for a person to confirm.
     */
    private function checkDashboardSettings(): void
    {
        $this->section('Stripe Dashboard settings');

        $capabilities = isset($this->account->capabilities) ? $this->account->capabilities->toArray() : [];
        $this->expect(($capabilities['card_payments'] ?? null) === 'active', 'card payments capability active',
            'card_payments capability is '.($capabilities['card_payments'] ?? 'missing').' - finish account activation in the Dashboard');
        $this->expect(($capabilities['transfers'] ?? null) === 'active', 'transfers capability active (withdrawals)',
            'transfers capability is '.($capabilities['transfers'] ?? 'missing').' - withdrawals cannot be paid out');

        $branding = isset($this->account->settings) ? $this->account->settings->branding : null;
        $branding?->logo || $branding?->icon
            ? $this->okLine('branding logo/icon set')
            : $this->warnLine('no logo or icon in Settings > Business > Branding - receipts and invoices show none');

        try {
            \Stripe\Account::all(['limit' => 1]);
            $this->okLine('Connect is enabled on this account');
        } catch (\Throwable $e) {
            $this->failLine('Connect is not set up - bank accounts and withdrawals will fail: '.$e->getMessage());
        }

        $this->checkCustomerPortal();
        $this->checkPaymentMethods();

        $this->line('  <fg=cyan>check</> not readable by the API, confirm in the Dashboard (this mode):');
        foreach ([
            'Connect > Platform profile saved, and Settings > Developers > API policies > "Accounts v1 support" on (a first bank add answering 201 proves both)',
            'Billing > Subscriptions and emails: Smart Retries on, "if all retries fail" = Cancel the subscription, 3-D Secure emails on',
            'Payment methods > Apple Pay: iOS certificate for the app\'s merchant ID uploaded',
            'Settings > Business > Public details: support email and phone',
        ] as $item) {
            $this->line("          - {$item}");
        }
    }

    private function checkCustomerPortal(): void
    {
        try {
            $portal = \Stripe\BillingPortal\Configuration::all(['is_default' => true, 'limit' => 1])->data[0] ?? null;
        } catch (\Throwable $e) {
            $this->failLine('could not read the Customer portal settings: '.$e->getMessage());

            return;
        }
        if (! $this->expect($portal !== null && $portal->active, 'Customer portal configured',
            'Customer portal is not saved - "Manage payment method" fails. Save Settings > Billing > Customer portal')) {
            return;
        }

        $features = $portal->features;
        $problems = array_keys(array_filter([
            'payment method update off' => ! $features->payment_method_update->enabled,
            'invoice history off' => ! $features->invoice_history->enabled,
            'cancel subscription off' => ! $features->subscription_cancel->enabled,
            'cancel should be "at end of period"' => $features->subscription_cancel->enabled && $features->subscription_cancel->mode !== 'at_period_end',
            'plan switching should be off' => $features->subscription_update->enabled,
            'privacy policy link missing' => empty($portal->business_profile->privacy_policy_url),
            'terms of service link missing' => empty($portal->business_profile->terms_of_service_url),
        ]));
        $problems === []
            ? $this->okLine('Customer portal: payment method, invoices, cancel at period end, links')
            : $this->warnLine('Customer portal: '.implode(', ', $problems));
    }

    private function checkPaymentMethods(): void
    {
        try {
            $config = collect(\Stripe\PaymentMethodConfiguration::all(['limit' => 100])->data)->first(fn ($c) => $c->is_default && $c->application === null);
        } catch (\Throwable $e) {
            $this->failLine('could not read the payment method settings: '.$e->getMessage());

            return;
        }
        if ($config === null) {
            $this->warnLine('no default payment method configuration found - check Settings > Payment methods');

            return;
        }

        $isOn = fn (string $method) => ($config->{$method}->display_preference->value ?? 'off') === 'on';
        $this->expect($isOn('card'), 'Cards on', 'Cards are off in Settings > Payment methods - the Payment Sheet cannot take a card');
        $wallets = array_values(array_filter(['apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay'], fn ($label, $method) => ! $isOn($method), ARRAY_FILTER_USE_BOTH));
        $wallets === []
            ? $this->okLine('Apple Pay and Google Pay on')
            : $this->warnLine(implode(' and ', $wallets).' off in Settings > Payment methods');
    }

    private function checkMail(): void
    {
        $this->section('Mail');
        $mailer = (string) config('mail.default');
        $this->expect(! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER={$mailer}", "MAIL_MAILER={$mailer} - no email is actually sent");
        $this->expect(filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false && config('mail.from.address') !== 'hello@example.com',
            'MAIL_FROM_ADDRESS set', 'MAIL_FROM_ADDRESS is missing or still the example value');
        $this->expect(filter_var(config('mail.admin_email'), FILTER_VALIDATE_EMAIL) !== false, 'ADMIN_EMAIL set', 'ADMIN_EMAIL is missing - admin alerts have nowhere to go');
    }

    private function expect(bool $passed, string $ok, string $problem): bool
    {
        $passed ? $this->okLine($ok) : $this->failLine($problem);

        return $passed;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function okLine(string $message): void
    {
        $this->line("  <fg=green>ok</>    {$message}");
    }

    private function warnLine(string $message): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>warn</>  {$message}");
    }

    private function failLine(string $message): void
    {
        $this->failures++;
        $this->line("  <fg=red>FAIL</>  {$message}");
    }
}
