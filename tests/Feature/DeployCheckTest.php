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
        $this->stripe->respond('GET /v1/account', self::account());
        $this->stripe->respond('GET /v1/prices/price_monthly', ['id' => 'price_monthly', 'object' => 'price', 'active' => true, 'unit_amount' => 499]);
    }

    private static function account(array $capabilities = ['card_payments' => 'active', 'transfers' => 'active']): array
    {
        return [
            'id' => 'acct_platform', 'object' => 'account', 'charges_enabled' => true, 'payouts_enabled' => true,
            'capabilities' => $capabilities,
            'settings' => ['branding' => ['logo' => 'file_logo', 'icon' => null]],
        ];
    }

    /** The Dashboard reads, answered as a correctly configured account would. */
    private function dashboard(?array $portal = null, ?array $paymentMethods = null, bool $connect = true): void
    {
        $connect
            ? $this->stripe->respond('GET /v1/accounts', ['object' => 'list', 'data' => []])
            : $this->stripe->respond('GET /v1/accounts', ['error' => ['type' => 'invalid_request_error', 'message' => 'You must sign up for Connect']], 400);
        $this->stripe->respond('GET /v1/billing_portal/configurations', ['object' => 'list', 'data' => $portal === [] ? [] : [array_replace_recursive([
            'id' => 'bpc_1', 'object' => 'billing_portal.configuration', 'active' => true, 'is_default' => true,
            'business_profile' => ['privacy_policy_url' => 'https://example.com/privacy', 'terms_of_service_url' => 'https://example.com/terms'],
            'features' => [
                'payment_method_update' => ['enabled' => true],
                'invoice_history' => ['enabled' => true],
                'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end'],
                'subscription_update' => ['enabled' => false],
            ],
        ], $portal ?? [])]]);
        $on = ['display_preference' => ['value' => 'on']];
        $this->stripe->respond('GET /v1/payment_method_configurations', ['object' => 'list', 'data' => [array_replace_recursive([
            'id' => 'pmc_1', 'object' => 'payment_method_configuration', 'is_default' => true, 'application' => null,
            'card' => $on, 'apple_pay' => $on, 'google_pay' => $on,
        ], $paymentMethods ?? [])]]);
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
        $this->dashboard();

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

    public function test_a_missing_customer_portal_fails(): void
    {
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);
        $this->dashboard(portal: []);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('Customer portal is not saved')
            ->assertFailed();
    }

    public function test_portal_features_that_differ_are_named(): void
    {
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);
        $this->dashboard(portal: ['features' => ['subscription_update' => ['enabled' => true]], 'business_profile' => ['terms_of_service_url' => null]]);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('plan switching should be off, terms of service link missing')
            ->assertSuccessful();
    }

    public function test_cards_off_fails_and_a_wallet_off_warns(): void
    {
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);
        $off = ['display_preference' => ['value' => 'off']];
        $this->dashboard(paymentMethods: ['card' => $off, 'google_pay' => $off]);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('Cards are off')
            ->expectsOutputToContain('Google Pay off')
            ->assertFailed();
    }

    public function test_an_inactive_transfers_capability_or_missing_connect_fails(): void
    {
        $this->stripe = new \Tests\Fakes\FakeStripeHttpClient;
        \Stripe\ApiRequestor::setHttpClient($this->stripe);
        $this->stripe->respond('GET /v1/account', self::account(['card_payments' => 'active', 'transfers' => 'inactive']));
        $this->stripe->respond('GET /v1/prices/price_monthly', ['id' => 'price_monthly', 'object' => 'price', 'active' => true, 'unit_amount' => 499]);
        $this->endpoint(DeployCheck::WEBHOOK_EVENTS);
        $this->dashboard(connect: false);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('transfers capability is inactive')
            ->expectsOutputToContain('Connect is not set up')
            ->assertFailed();
    }
}
