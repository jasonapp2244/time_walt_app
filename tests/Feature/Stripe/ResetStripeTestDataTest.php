<?php

namespace Tests\Feature\Stripe;

use App\Models\Feedback;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\StripeCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserBankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * stripe:reset-test-data, the one-off cleanup when the app moves to a new
 * Stripe account or mode (test → live).
 */
class ResetStripeTestDataTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();

        $this->user = User::factory()->create(['free_locks_used' => 2, 'free_withdrawals_used' => 2]);
        Transfer::factory()->create([
            'user_id' => $this->user->id,
            'hold_id' => PaymentHold::factory()->create(['user_id' => $this->user->id])->id,
        ]);
        Subscription::factory()->create(['user_id' => $this->user->id]);
        UserBankAccount::factory()->create(['user_id' => $this->user->id]);
        StripeCustomer::create(['user_id' => $this->user->id, 'stripe_customer_id' => 'cus_old']);
        StripeConnectAccount::create(['user_id' => $this->user->id, 'connect_account_id' => 'acct_old']);
        Feedback::factory()->create(['user_id' => $this->user->id]);
    }

    private function missing(): array
    {
        return ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such object']];
    }

    private function oldIdsAreGone(): void
    {
        $this->stripe->respond('GET /v1/customers/cus_old', $this->missing(), 404);
        $this->stripe->respond('GET /v1/accounts/acct_old', $this->missing(), 404);
    }

    public function test_dry_run_shows_counts_and_deletes_nothing(): void
    {
        $this->oldIdsAreGone();

        $this->artisan('stripe:reset-test-data')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('stripe_customers', 1);
        $this->assertSame(2, $this->user->fresh()->free_withdrawals_used);
    }

    public function test_force_removes_stale_stripe_data_and_keeps_users_and_plans(): void
    {
        $this->oldIdsAreGone();
        $plans = SubscriptionPlan::count();

        $this->artisan('stripe:reset-test-data', ['--force' => true])->assertSuccessful();

        foreach (['transfers', 'payment_holds', 'payments', 'subscription_invoices', 'subscriptions',
            'stripe_customers', 'stripe_connect_accounts', 'user_bank_accounts', 'stripe_webhook_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $user = $this->user->fresh();
        $this->assertNotNull($user);
        $this->assertSame(0, $user->free_locks_used);
        $this->assertSame(0, $user->free_withdrawals_used);
        $this->assertSame($plans, SubscriptionPlan::count());
        $this->assertDatabaseCount('feedbacks', 1);
    }

    public function test_refuses_while_the_saved_ids_still_exist_under_the_current_key(): void
    {
        $this->stripe->respond('GET /v1/customers/cus_old', ['id' => 'cus_old', 'object' => 'customer']);
        $this->stripe->respond('GET /v1/accounts/acct_old', $this->missing(), 404);

        $this->artisan('stripe:reset-test-data', ['--force' => true])
            ->expectsOutputToContain('cus_old')
            ->assertFailed();

        $this->assertDatabaseCount('stripe_customers', 1);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_a_test_mode_connect_account_read_with_a_live_key_counts_as_stale(): void
    {
        $this->stripe->respond('GET /v1/customers/cus_old', $this->missing(), 404);
        $this->stripe->respond('GET /v1/accounts/acct_old', ['error' => [
            'type' => 'invalid_request_error',
            'message' => 'The account acct_old was a test account created with a testmode key, and therefore can only be used with testmode keys.',
        ]], 400);

        $this->artisan('stripe:reset-test-data', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('stripe_connect_accounts', 0);
        $this->assertDatabaseCount('transfers', 0);
    }

    public function test_refuses_when_stripe_cannot_be_reached(): void
    {
        $this->stripe->respond('GET /v1/customers/cus_old', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);

        $this->artisan('stripe:reset-test-data', ['--force' => true])->assertFailed();

        $this->assertDatabaseCount('transfers', 1);
    }
}
