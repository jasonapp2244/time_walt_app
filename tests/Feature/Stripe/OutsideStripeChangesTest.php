<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendDisputeNotification;
use App\Jobs\SendManualReversalNotification;
use App\Mail\Stripe\AdminManualReversalMail;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\SubscriptionInvoice;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * Money moved directly in the Stripe Dashboard, outside the app:
 *  - gap 1: a withdrawal reversed by hand goes back into the user's vault once;
 *  - gap 2: refunds and chargebacks on subscription payments are recorded for
 *    the admin (no vault money involved).
 */
class OutsideStripeChangesTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();
        config(['mail.admin_email' => 'admin@example.com']);

        $this->user = User::factory()->create(['full_name' => 'Brian Foster', 'email' => 'brian.foster@example.com']);
    }

    // ─── Gap 1: transfer reversed by hand ────────────────────────────────────

    private function withdrawnDeposit(): array
    {
        $payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 100]);
        $hold = PaymentHold::factory()->create([
            'payment_id' => $payment->id, 'user_id' => $this->user->id, 'amount' => 100,
            'remaining_amount' => 30, 'status' => 'partial_transferred',
            'hold_start_at' => now()->subDays(5), 'hold_end_at' => now()->subDays(2),
        ]);
        $transfer = Transfer::factory()->create([
            'hold_id' => $hold->id, 'user_id' => $this->user->id, 'amount' => 70,
            'stripe_transfer_id' => 'tr_manual_1', 'status' => 'completed',
        ]);

        return [$hold, $transfer];
    }

    private function transferReversed(array $reversals, bool $hasMore = false): void
    {
        $this->postStripeWebhook($this->stripeEvent('transfer.reversed', [
            'id' => 'tr_manual_1', 'object' => 'transfer', 'amount' => 7000,
            'amount_reversed' => array_sum(array_column($reversals, 'amount')),
            'reversals' => ['object' => 'list', 'data' => $reversals, 'has_more' => $hasMore],
        ]))->assertOk();
    }

    public function test_a_transfer_reversed_by_hand_goes_back_into_the_vault_once(): void
    {
        [$hold, $transfer] = $this->withdrawnDeposit();
        $reversals = [['id' => 'trr_hand_1', 'object' => 'transfer_reversal', 'amount' => 2000, 'metadata' => []]];

        $this->transferReversed($reversals);
        $this->transferReversed($reversals); // replayed delivery

        $transfer->refresh();
        $this->assertSame('20.00', $transfer->reversed_amount);
        $this->assertSame('20.00', $transfer->manual_reversed_amount);
        $this->assertSame('50.00', $hold->refresh()->remaining_amount);
        $this->assertSame('partial_transferred', $hold->status);
        $this->assertSame(0, Artisan::call('refunds:audit'));
        Queue::assertPushed(SendManualReversalNotification::class, 1);
        Queue::assertPushed(SendManualReversalNotification::class, fn ($job) => $job->amount === 20.0);

        // A second hand reversal later adds only the new part.
        $this->transferReversed([...$reversals, ['id' => 'trr_hand_2', 'object' => 'transfer_reversal', 'amount' => 500, 'metadata' => []]]);
        $this->assertSame('25.00', $transfer->refresh()->manual_reversed_amount);
        $this->assertSame('55.00', $hold->refresh()->remaining_amount);
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    public function test_reversals_made_by_our_refunds_are_not_counted_twice(): void
    {
        [$hold, $transfer] = $this->withdrawnDeposit();

        $this->transferReversed([['id' => 'trr_refund', 'object' => 'transfer_reversal', 'amount' => 3000, 'metadata' => ['refund_id' => '7']]]);

        $this->assertSame('0.00', $transfer->refresh()->reversed_amount);
        $this->assertSame('30.00', $hold->refresh()->remaining_amount);
        Queue::assertNotPushed(SendManualReversalNotification::class);
    }

    public function test_a_long_reversal_list_is_read_in_full_from_stripe(): void
    {
        [$hold] = $this->withdrawnDeposit();
        $this->stripe->respond('GET /v1/transfers/tr_manual_1/reversals', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'trr_a', 'object' => 'transfer_reversal', 'amount' => 1000, 'metadata' => []],
            ['id' => 'trr_b', 'object' => 'transfer_reversal', 'amount' => 1500, 'metadata' => []],
        ]]);

        $this->transferReversed([['id' => 'trr_a', 'object' => 'transfer_reversal', 'amount' => 1000, 'metadata' => []]], hasMore: true);

        $this->assertSame('55.00', $hold->refresh()->remaining_amount);
    }

    public function test_a_transfer_this_app_did_not_make_is_ignored(): void
    {
        [$hold] = $this->withdrawnDeposit();

        $this->postStripeWebhook($this->stripeEvent('transfer.reversed', [
            'id' => 'tr_someone_else', 'object' => 'transfer', 'amount' => 500,
            'reversals' => ['object' => 'list', 'data' => [['id' => 'trr_x', 'amount' => 500, 'metadata' => []]], 'has_more' => false],
        ]))->assertOk();

        $this->assertSame('30.00', $hold->refresh()->remaining_amount);
        Queue::assertNotPushed(SendManualReversalNotification::class);
    }

    public function test_the_admin_sees_the_hand_reversal(): void
    {
        [, $transfer] = $this->withdrawnDeposit();
        $this->transferReversed([['id' => 'trr_hand_1', 'object' => 'transfer_reversal', 'amount' => 2000, 'metadata' => []]]);

        $mail = new AdminManualReversalMail($transfer->fresh(), 20.0);
        $this->assertSame("[Admin] Withdrawal reversed in Stripe: \$20.00 back in Brian Foster's vault", $mail->envelope()->subject);
        $mail->assertSeeInHtml('it was put back in the user');

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'megan.carter@example.com']);
        $this->actingAs($admin)->get(route('admin.transfers.index'))
            ->assertOk()
            ->assertSee('Reversed in Stripe $20.00');
    }

    // ─── Gap 2: subscription payments ────────────────────────────────────────

    private function subscriptionPayment(): SubscriptionInvoice
    {
        $invoice = SubscriptionInvoice::factory()->create(['user_id' => $this->user->id, 'stripe_invoice_id' => 'in_sub_1', 'amount' => 9.99]);
        $this->stripe->respond('GET /v1/invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_sub_1'],
        ]]);

        return $invoice;
    }

    public function test_a_subscription_payment_refunded_in_stripe_is_recorded(): void
    {
        $invoice = $this->subscriptionPayment();
        $this->stripe->respond('GET /v1/invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_sub_1'],
        ]]);
        $charge = ['id' => 'ch_sub_1', 'object' => 'charge', 'payment_intent' => 'pi_sub_1', 'amount' => 999, 'amount_refunded' => 999, 'refunds' => ['object' => 'list', 'data' => []]];

        $this->postStripeWebhook($this->stripeEvent('charge.refunded', $charge))->assertOk();
        $this->postStripeWebhook($this->stripeEvent('charge.refunded', $charge + ['livemode' => false]))->assertOk(); // replay

        $invoice->refresh();
        $this->assertSame('9.99', $invoice->refunded_amount);
        $this->assertNotNull($invoice->refunded_at);
        $this->assertSame(0, \App\Models\Refund::count(), 'no deposit refund row');

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'megan.carter@example.com']);
        $this->actingAs($admin)->get(route('admin.subscription-payments.index'))
            ->assertOk()
            ->assertSee('Refunded in Stripe 9.99');
    }

    public function test_a_refund_event_on_a_subscription_reads_the_charge_total(): void
    {
        $invoice = $this->subscriptionPayment();
        $this->stripe->respond('GET /v1/charges/ch_sub_2', ['id' => 'ch_sub_2', 'object' => 'charge', 'amount_refunded' => 500]);

        $this->postStripeWebhook($this->stripeEvent('refund.created', [
            'id' => 're_sub_1', 'object' => 'refund', 'amount' => 500, 'currency' => 'usd',
            'charge' => 'ch_sub_2', 'payment_intent' => 'pi_sub_2', 'status' => 'succeeded', 'metadata' => [],
        ]))->assertOk();

        $this->assertSame('5.00', $invoice->refresh()->refunded_amount);
    }

    public function test_a_chargeback_on_a_subscription_payment_is_recorded_and_emailed(): void
    {
        $invoice = $this->subscriptionPayment();
        $dispute = ['id' => 'dp_sub_1', 'object' => 'dispute', 'amount' => 999, 'currency' => 'usd', 'charge' => 'ch_sub_1',
            'payment_intent' => 'pi_sub_1', 'reason' => 'unrecognized', 'status' => 'needs_response'];

        $this->postStripeWebhook($this->stripeEvent('charge.dispute.created', $dispute))->assertOk();

        $row = Dispute::sole();
        $this->assertSame($invoice->id, $row->subscription_invoice_id);
        $this->assertNull($row->payment_id);
        $this->assertSame('open', $row->outcome);
        Queue::assertPushed(SendDisputeNotification::class, fn ($job) => $job->event === 'opened');

        $this->stripe->respond('GET /v1/invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_sub_1'],
        ]]);
        $this->postStripeWebhook($this->stripeEvent('charge.dispute.closed', ['status' => 'lost'] + $dispute))->assertOk();

        $this->assertSame('lost', $row->refresh()->outcome);
        Queue::assertPushed(SendDisputeNotification::class, fn ($job) => $job->event === 'lost');

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'megan.carter@example.com']);
        $this->actingAs($admin)->get(route('admin.subscription-payments.index'))->assertOk()->assertSee('Chargeback lost');
        $this->actingAs($admin)->get(route('admin.refunds.index'))->assertOk()->assertSee('Subscription');

        $mail = new \App\Mail\Stripe\AdminDisputeMail($row->fresh(), 'opened');
        $mail->assertSeeInHtml('of a subscription payment');
        $mail->assertDontSeeInHtml('This deposit is now frozen');
    }

    public function test_a_payment_that_is_neither_a_deposit_nor_a_subscription_is_ignored(): void
    {
        $this->stripe->respond('GET /v1/invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => []]);

        $this->postStripeWebhook($this->stripeEvent('charge.dispute.created', [
            'id' => 'dp_x', 'object' => 'dispute', 'amount' => 100, 'currency' => 'usd',
            'payment_intent' => 'pi_other', 'status' => 'needs_response',
        ]))->assertOk();

        $this->assertSame(0, Dispute::count());
    }
}
