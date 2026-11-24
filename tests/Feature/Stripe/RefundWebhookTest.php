<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendRefundFailedNotification;
use App\Jobs\SendRefundIssuedNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * Refund webhooks keep the vault in step with Stripe: Dashboard refunds are
 * recorded, and a refund that fails later puts the money back.
 */
class RefundWebhookTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();

        $this->user = User::factory()->create(['full_name' => 'Emily Parker', 'email' => 'emily.parker@example.com']);
    }

    private function deposit(float $amount, array $hold = []): PaymentHold
    {
        $payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => $amount]);

        return PaymentHold::factory()->create(array_merge([
            'payment_id' => $payment->id,
            'user_id' => $this->user->id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'status' => 'ready_for_transfer',
            'hold_start_at' => now()->subDays(3),
            'hold_end_at' => now()->subDay(),
        ], $hold));
    }

    private function stripeRefund(PaymentHold $hold, array $overrides = []): array
    {
        return array_merge([
            'id' => 're_dash_1',
            'object' => 'refund',
            'amount' => (int) round($hold->amount * 100),
            'currency' => 'usd',
            'payment_intent' => $hold->payment->payment_intent_id,
            'status' => 'succeeded',
            'metadata' => [],
        ], $overrides);
    }

    public function test_a_refund_made_in_the_stripe_dashboard_is_recorded_and_taken_out_of_the_vault(): void
    {
        $hold = $this->deposit(50);

        $this->postStripeWebhook($this->stripeEvent('refund.created', $this->stripeRefund($hold)))->assertOk();

        $refund = Refund::sole();
        $this->assertSame('stripe_dashboard', $refund->source);
        $this->assertSame('succeeded', $refund->status);
        $this->assertNull($refund->admin_id);
        $this->assertFalse($refund->needs_attention);
        $this->assertSame('0.00', $hold->refresh()->remaining_amount);
        $this->assertSame('refunded', $hold->status);
        $this->assertSame('50.00', $hold->payment->refresh()->refunded_amount);
        Queue::assertPushed(SendRefundIssuedNotification::class);
    }

    public function test_the_same_refund_arriving_twice_is_counted_once(): void
    {
        $hold = $this->deposit(50);
        $object = $this->stripeRefund($hold);

        $this->postStripeWebhook($this->stripeEvent('refund.created', $object))->assertOk();
        $this->postStripeWebhook($this->stripeEvent('refund.updated', $object))->assertOk();
        $this->postStripeWebhook($this->stripeEvent('charge.refunded', [
            'id' => 'ch_1', 'object' => 'charge', 'refunds' => ['object' => 'list', 'data' => [$object]],
        ]))->assertOk();

        $this->assertSame(1, Refund::count());
        $this->assertSame('50.00', $hold->payment->refresh()->refunded_amount);
    }

    public function test_a_dashboard_refund_larger_than_the_vault_is_flagged(): void
    {
        $hold = $this->deposit(100, ['remaining_amount' => 30, 'status' => 'partial_transferred']);
        \App\Models\Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $this->user->id, 'amount' => 70, 'status' => 'completed']);

        $this->postStripeWebhook($this->stripeEvent('refund.created', $this->stripeRefund($hold)))->assertOk();

        $refund = Refund::sole();
        $this->assertTrue($refund->needs_attention);
        $this->assertSame('30.00', $refund->vault_amount);
        $this->assertSame('0.00', $hold->refresh()->remaining_amount);
        $this->assertSame('transferred', $hold->status);
    }

    public function test_a_dashboard_refund_on_an_untouched_remaining_amount_counts_earlier_withdrawals(): void
    {
        $hold = $this->deposit(100, ['remaining_amount' => null, 'status' => 'partial_transferred']);
        \App\Models\Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $this->user->id, 'amount' => 70, 'status' => 'completed']);

        $this->postStripeWebhook($this->stripeEvent('refund.created', $this->stripeRefund($hold, ['amount' => 5000])))->assertOk();

        $refund = Refund::sole();
        $this->assertSame('30.00', $refund->vault_amount);
        $this->assertTrue($refund->needs_attention);
        $this->assertSame('0.00', $hold->refresh()->remaining_amount);
        $this->assertSame('transferred', $hold->status);
    }

    public function test_a_refund_that_fails_later_puts_the_money_back_in_the_vault(): void
    {
        $hold = $this->deposit(100, ['remaining_amount' => 0, 'status' => 'refunded']);
        $hold->payment->update(['refunded_amount' => 100]);
        $refund = Refund::factory()->create([
            'payment_id' => $hold->payment_id,
            'payment_hold_id' => $hold->id,
            'user_id' => $this->user->id,
            'stripe_refund_id' => 're_admin_1',
            'amount' => 100,
            'vault_amount' => 100,
            'hold_status_before' => 'ready_for_transfer',
            'status' => 'succeeded',
        ]);

        $this->postStripeWebhook($this->stripeEvent('refund.failed', $this->stripeRefund($hold, [
            'id' => 're_admin_1', 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card',
        ])))->assertOk();

        $this->assertSame('failed', $refund->refresh()->status);
        $this->assertSame('expired_or_canceled_card', $refund->failure_reason);
        $this->assertSame('100.00', $hold->refresh()->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertSame('0.00', $hold->payment->refresh()->refunded_amount);
        Queue::assertPushed(SendRefundFailedNotification::class);

        // A repeat of the failure changes nothing more.
        $this->postStripeWebhook($this->stripeEvent('refund.failed', $this->stripeRefund($hold, ['id' => 're_admin_1', 'status' => 'failed'])))->assertOk();
        $this->assertSame('100.00', $hold->refresh()->remaining_amount);
    }

    public function test_refund_updated_stores_the_bank_tracking_number(): void
    {
        $hold = $this->deposit(40, ['remaining_amount' => 0, 'status' => 'refunded']);
        $refund = Refund::factory()->create([
            'payment_id' => $hold->payment_id,
            'payment_hold_id' => $hold->id,
            'stripe_refund_id' => 're_admin_2',
            'amount' => 40,
            'status' => 'pending',
            'arn' => null,
        ]);

        $this->postStripeWebhook($this->stripeEvent('refund.updated', $this->stripeRefund($hold, [
            'id' => 're_admin_2',
            'destination_details' => ['type' => 'card', 'card' => ['reference' => '74567890123456789012345', 'reference_type' => 'acquirer_reference_number', 'type' => 'refund']],
        ])))->assertOk();

        $refund->refresh();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('74567890123456789012345', $refund->arn);
        Queue::assertNotPushed(SendRefundIssuedNotification::class);
    }

    public function test_a_partial_dashboard_refund_leaves_the_rest_in_the_vault(): void
    {
        $hold = $this->deposit(100);

        $this->postStripeWebhook($this->stripeEvent('refund.created', $this->stripeRefund($hold, ['amount' => 3000])))->assertOk();

        $this->assertSame('70.00', $hold->refresh()->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertSame('30.00', $hold->payment->refresh()->refunded_amount);
        $this->assertFalse(Refund::sole()->needs_attention);
    }

    public function test_a_refund_for_an_unknown_payment_is_ignored(): void
    {
        $this->stripe->respond('GET /v1/invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => []]); // not a subscription either
        $this->postStripeWebhook($this->stripeEvent('refund.created', [
            'id' => 're_other', 'object' => 'refund', 'amount' => 999, 'currency' => 'usd', 'payment_intent' => 'pi_not_a_deposit', 'status' => 'succeeded',
        ]))->assertOk();

        $this->assertSame(0, Refund::count());
    }

    public function test_audit_reports_a_deposit_that_does_not_add_up(): void
    {
        $good = $this->deposit(60, ['remaining_amount' => 0, 'status' => 'refunded']);
        Refund::factory()->create(['payment_id' => $good->payment_id, 'payment_hold_id' => $good->id, 'amount' => 60]);

        $this->assertSame(0, Artisan::call('refunds:audit'));
        $this->assertSame([], Cache::get('refunds:audit:mismatches'));

        $bad = $this->deposit(80, ['remaining_amount' => 80, 'status' => 'refunded']);
        Refund::factory()->create(['payment_id' => $bad->payment_id, 'payment_hold_id' => $bad->id, 'amount' => 80]);

        $this->assertSame(1, Artisan::call('refunds:audit'));
        $mismatches = Cache::get('refunds:audit:mismatches');
        $this->assertCount(1, $mismatches);
        $this->assertSame($bad->id, $mismatches[0]['hold_id']);
        $this->assertSame(-80.0, $mismatches[0]['difference']);
    }
}
