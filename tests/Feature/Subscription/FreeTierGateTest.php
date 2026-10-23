<?php

namespace Tests\Feature\Subscription;

use App\Models\AppSetting;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeCustomer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\FreeTierService;
use App\Services\PaymentHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * 2 free locks + 2 free withdrawals; the 3rd attempt returns 402
 * subscription_required before any money moves.
 */
class FreeTierGateTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();
        config(['app.free_lock_limit' => 2, 'app.free_withdraw_limit' => 2, 'app.withdraw_limit_enabled' => true]);

        $this->user = User::factory()->create();
    }

    private function used(int $locks = 0, int $withdrawals = 0): void
    {
        $this->user->forceFill(['free_locks_used' => $locks, 'free_withdrawals_used' => $withdrawals])->save();
    }

    private function subscribe(): void
    {
        Subscription::factory()->create(['user_id' => $this->user->id]);
    }

    private function lock()
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/stripe/create-payment-intent', [
            'amount' => 25,
            'currency' => 'usd',
            'hold_period_type' => 'custom',
            'hold_start_at' => now()->addDay()->toDateString(),
            'hold_end_at' => now()->addDays(8)->toDateString(),
        ]);
    }

    private function stripeAcceptsPaymentIntent(): void
    {
        $this->stripe->respond('POST /v1/customers', ['id' => 'cus_fake', 'object' => 'customer']);
        $this->stripe->respond('POST /v1/ephemeral_keys', ['id' => 'ephkey_fake', 'object' => 'ephemeral_key', 'secret' => 'ek_test_fake']);
        $this->stripe->respond('POST /v1/payment_intents', ['id' => 'pi_fake', 'object' => 'payment_intent', 'client_secret' => 'pi_fake_secret_x']);
    }

    // ── Locks ────────────────────────────────────────────────────────────────

    public function test_the_second_free_lock_is_allowed(): void
    {
        $this->used(locks: 1);
        $this->stripeAcceptsPaymentIntent();

        $this->lock()->assertOk()->assertJsonPath('data.client_secret', 'pi_fake_secret_x');
    }

    public function test_the_third_lock_is_blocked_before_the_card_is_touched(): void
    {
        $this->used(locks: 2);

        $this->lock()
            ->assertStatus(402)
            ->assertExactJson([
                'success' => false,
                'code' => 'subscription_required',
                'action' => 'lock',
                'used' => 2,
                'limit' => 2,
                'message' => 'You have used your 2 free locks. Subscribe to continue.',
            ]);

        $this->assertSame([], $this->stripe->requests);
    }

    /**
     * The user's deposits Stripe lists for the free-tier check.
     */
    private function stripeListsDeposits(array $intents): void
    {
        StripeCustomer::create(['user_id' => $this->user->id, 'stripe_customer_id' => 'cus_fake']);

        $this->stripe->respond('GET /v1/payment_intents', [
            'object' => 'list',
            'has_more' => false,
            'url' => '/v1/payment_intents',
            'data' => array_map(fn (array $intent) => $intent + [
                'object' => 'payment_intent',
                'metadata' => ['user_id' => (string) $this->user->id, 'hold_period_type' => 'custom'],
            ], $intents),
        ]);
    }

    public function test_an_unpaid_earlier_deposit_is_cancelled_before_the_next_one_starts(): void
    {
        $this->used(locks: 1);
        $this->stripeListsDeposits([['id' => 'pi_open', 'status' => 'requires_payment_method']]);
        $this->stripe->respond('POST /v1/payment_intents/pi_open/cancel', ['id' => 'pi_open', 'object' => 'payment_intent', 'status' => 'canceled']);
        $this->stripe->respond('POST /v1/ephemeral_keys', ['id' => 'ephkey_fake', 'object' => 'ephemeral_key', 'secret' => 'ek_test_fake']);
        $this->stripe->respond('POST /v1/payment_intents', ['id' => 'pi_new', 'object' => 'payment_intent', 'client_secret' => 'pi_new_secret_x']);

        $this->lock()->assertOk()->assertJsonPath('data.client_secret', 'pi_new_secret_x');

        // Only one free deposit can be paid: the earlier one can no longer be.
        $this->assertCount(1, $this->stripe->requestsTo('POST', '/v1/payment_intents/pi_open/cancel'));
    }

    public function test_a_charged_deposit_not_yet_recorded_counts_as_the_last_free_lock(): void
    {
        // 1 lock recorded, and a 2nd already charged whose hold is not created yet.
        $this->used(locks: 1);
        $this->stripeListsDeposits([['id' => 'pi_charged', 'status' => 'succeeded']]);

        $this->lock()
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('used', 2);

        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/payment_intents'));
    }

    public function test_a_recorded_deposit_is_not_counted_twice(): void
    {
        $this->used(locks: 1);
        Payment::factory()->create(['user_id' => $this->user->id, 'payment_intent_id' => 'pi_recorded']);
        $this->stripeListsDeposits([['id' => 'pi_recorded', 'status' => 'succeeded']]);
        $this->stripe->respond('POST /v1/ephemeral_keys', ['id' => 'ephkey_fake', 'object' => 'ephemeral_key', 'secret' => 'ek_test_fake']);
        $this->stripe->respond('POST /v1/payment_intents', ['id' => 'pi_new', 'object' => 'payment_intent', 'client_secret' => 'pi_new_secret_x']);

        $this->lock()->assertOk();
    }

    public function test_a_subscriber_is_never_blocked(): void
    {
        $this->used(locks: 5);
        $this->subscribe();
        $this->stripeAcceptsPaymentIntent();

        $this->lock()->assertOk();
    }

    public function test_an_ended_subscription_brings_the_limit_back(): void
    {
        $this->used(locks: 2);
        Subscription::factory()->expired()->create(['user_id' => $this->user->id]);

        $this->lock()->assertStatus(402);
    }

    public function test_a_lock_is_counted_when_its_hold_is_created(): void
    {
        $payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 25]);

        app(PaymentHoldService::class)->createFromPayment($payment, ['hold_days' => 7]);

        $this->assertSame(1, $this->user->fresh()->free_locks_used);
    }

    public function test_a_subscribers_lock_is_not_counted(): void
    {
        $this->subscribe();
        $payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 25]);

        app(PaymentHoldService::class)->createFromPayment($payment, ['hold_days' => 7]);

        $this->assertSame(0, $this->user->fresh()->free_locks_used);
    }

    public function test_confirming_a_payment_is_never_blocked_even_past_the_limit(): void
    {
        // The card was already charged; refusing here would lose the money.
        $this->used(locks: 2);
        $this->stripe->respond('GET /v1/payment_intents/pi_paid', [
            'id' => 'pi_paid',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 2500,
            'currency' => 'usd',
            'latest_charge' => null,
            'metadata' => ['user_id' => (string) $this->user->id, 'hold_days' => '7', 'hold_period_type' => 'custom'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stripe/confirm-payment', ['payment_intent_id' => 'pi_paid'])
            ->assertOk();

        $this->assertSame(1, PaymentHold::where('user_id', $this->user->id)->count());
        $this->assertSame(3, $this->user->fresh()->free_locks_used);
    }

    // ── Withdrawals ──────────────────────────────────────────────────────────

    private function withdraw()
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/payment-holds/withdraw', ['amount' => 10]);
    }

    public function test_the_third_withdrawal_is_blocked_before_stripe_is_called(): void
    {
        $this->used(withdrawals: 2);

        $this->withdraw()
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required')
            ->assertJsonPath('action', 'withdraw')
            ->assertJsonPath('message', 'You have used your 2 free withdrawals. Subscribe to continue.');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_the_third_single_hold_payout_is_blocked_too(): void
    {
        $this->used(withdrawals: 2);
        $hold = PaymentHold::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/payment-holds/{$hold->id}/request-payout")
            ->assertStatus(402);
    }

    public function test_a_failed_withdrawal_does_not_use_up_a_free_one(): void
    {
        // No holds, so the controller answers 400 "No funds available".
        $this->used(withdrawals: 1);

        $this->withdraw()->assertStatus(400);

        $this->assertSame(1, $this->user->fresh()->free_withdrawals_used);
    }

    public function test_a_successful_withdrawal_uses_one_up(): void
    {
        $this->used(withdrawals: 1);
        \App\Models\StripeConnectAccount::create([
            'user_id' => $this->user->id,
            'connect_account_id' => 'acct_fake',
            'status' => 'verified',
        ]);
        PaymentHold::factory()->create([
            'user_id' => $this->user->id,
            'payment_id' => Payment::factory()->create([
                'user_id' => $this->user->id,
                'stripe_data' => ['latest_charge' => 'ch_fake'],
            ])->id,
            'amount' => 50,
            'remaining_amount' => 50,
            'hold_end_at' => now()->subDay(),
            'status' => 'ready_for_transfer',
        ]);
        $this->stripe->respond('POST /v1/transfers', ['id' => 'tr_fake', 'object' => 'transfer']);

        $this->withdraw()->assertOk();

        $this->assertSame(2, $this->user->fresh()->free_withdrawals_used);
    }

    public function test_withdrawals_are_free_when_the_admin_switches_the_limit_off(): void
    {
        AppSetting::write('withdraw_limit_enabled', false);
        $this->used(withdrawals: 9);

        $this->withdraw()->assertStatus(400); // reaches the controller: no funds

        $this->assertSame(9, $this->user->fresh()->free_withdrawals_used);
    }

    public function test_a_subscriber_withdraws_without_being_counted(): void
    {
        $this->used(withdrawals: 2);
        $this->subscribe();

        $this->withdraw()->assertStatus(400); // reaches the controller

        $this->assertSame(2, $this->user->fresh()->free_withdrawals_used);
    }

    public function test_the_last_free_withdrawal_can_only_be_claimed_once(): void
    {
        $this->used(withdrawals: 1);
        $freeTier = app(FreeTierService::class);

        $this->assertTrue($freeTier->claimWithdrawal($this->user));
        $this->assertFalse($freeTier->claimWithdrawal($this->user));
        $this->assertSame(2, $this->user->fresh()->free_withdrawals_used);
    }

    public function test_admin_edited_limits_override_the_config(): void
    {
        AppSetting::write('free_lock_limit', 5);
        $this->used(locks: 2);
        $this->stripeAcceptsPaymentIntent();

        $this->lock()->assertOk();
    }
}
