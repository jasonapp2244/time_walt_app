<?php

namespace Tests\Feature;

use App\Console\Commands\DeployCheck;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * deploy:check, the read-only settings check deploy.sh runs after a deploy.
 */
class DeployCheckTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private const URL = 'https://api.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.url' => self::URL,
            'mail.default' => 'smtp',
            'mail.from.address' => 'no-reply@example.com',
            'mail.admin_email' => 'admin@example.com',
        ]);
        SubscriptionPlan::factory()->create(['name' => 'Monthly', 'price' => 4.99, 'stripe_price_id' => 'price_monthly', 'is_active' => true]);
        $this->stripe->respond('GET /v1/account', ['id' => 'acct_platform', 'object' => 'account', 'charges_enabled' => true, 'payouts_enabled' => true]);
        $this->stripe->respond('GET /v1/prices/price_monthly', ['id' => 'price_monthly', 'object' => 'price', 'active' => true, 'unit_amount' => 499]);
    }

    private function endpoint(array $events, string $status = 'enabled'): void
    {
        $this->stripe->respond('GET /v1/webhook_endpoints', ['object' => 'list', 'data' => [[
            'id' => 'we_1', 'object' => 'webhook_endpoint', 'url' => self::URL.'/api/stripe/webhook',
            'status' => $status, 'enabled_events' => $events,
        ]]]);
    }

    public function test_a_correct_setup_passes(): void
    {
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('deploy:check OK')
            ->assertSuccessful();
    }

    public function test_a_webhook_missing_events_fails_and_names_them(): void
    {
        $this->endpoint(['payment_intent.succeeded']);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('invoice.paid')
            ->assertFailed();
    }

    public function test_debug_mode_and_a_log_mailer_fail(): void
    {
        config(['app.debug' => true, 'mail.default' => 'log']);
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('APP_DEBUG is true')
            ->expectsOutputToContain('MAIL_MAILER=log')
            ->assertFailed();
    }

    public function test_a_plan_price_missing_from_the_account_fails(): void
    {
        $this->stripe = new \Tests\Fakes\FakeStripeHttpClient;
        \Stripe\ApiRequestor::setHttpClient($this->stripe);
        $this->stripe->respond('GET /v1/account', ['id' => 'acct_platform', 'object' => 'account']);
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);
        $this->stripe->respond('GET /v1/prices/price_monthly', ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such price']], 404);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('SubscriptionPlanSeeder')
            ->assertFailed();
    }
}
