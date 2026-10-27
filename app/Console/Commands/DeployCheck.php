<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;

/**
 * Post-deploy check of the settings the app cannot run correctly without:
 * environment, Stripe keys, the webhook endpoint, plan prices and mail.
 * Reads only; changes nothing. Exits non-zero when anything FAILs, so
 * deploy.sh can point at it.
 */
class DeployCheck extends Command
{
    protected $signature = 'deploy:check';

    protected $description = 'Check environment, Stripe, webhook, plans and mail settings after a deploy (read-only)';

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

    public function handle(): int
    {
        $this->checkEnvironment();
        $stripeReady = $this->checkStripeKeys();
        if ($stripeReady) {
            $this->checkWebhook();
            $this->checkPlans();
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
