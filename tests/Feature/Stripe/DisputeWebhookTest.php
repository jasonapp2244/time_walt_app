<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendDisputeNotification;
use App\Mail\Stripe\AdminDisputeMail;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Services\RefundService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * Chargebacks: an open dispute freezes the deposit; a lost one takes the
 * money out of the vault so it can never be paid out twice; a won one lifts
 * the freeze. The ledger (refunds:audit) adds up in every case.
 */
class DisputeWebhookTest extends TestCase
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

        $this->user = User::factory()->create(['full_name' => 'Rachel Turner', 'email' => 'rachel.turner@example.com']);
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

    private function dispute(PaymentHold $hold, string $event, string $status, array $overrides = []): void
    {
        $this->postStripeWebhook($this->stripeEvent($event, array_merge([
            'id' => 'dp_test_1',
            'object' => 'dispute',
            'amount' => (int) round($hold->amount * 100),
            'currency' => 'usd',
            'charge' => 'ch_test_1',
            'payment_intent' => $hold->payment->payment_intent_id,
            'reason' => 'fraudulent',
            'status' => $status,
            'evidence_details' => ['due_by' => now()->addDays(7)->timestamp],
        ], $overrides)))->assertOk();
    }

    public function test_an_open_dispute_freezes_the_deposit_everywhere(): void
    {
        $hold = $this->deposit(100);
        StripeConnectAccount::create(['user_id' => $this->user->id, 'connect_account_id' => 'acct_dispute_1', 'status' => 'verified', 'payouts_enabled' => true]);

        $this->dispute($hold, 'charge.dispute.created', 'needs_response');

        $dispute = Dispute::sole();
        $this->assertSame('open', $dispute->outcome);
        $this->assertSame('Fraudulent', $dispute->reasonLabel());
        $this->assertNotNull($hold->refresh()->disputed_at);
        $this->assertSame('100.00', $hold->remaining_amount, 'money is frozen, not taken');
        Queue::assertPushed(SendDisputeNotification::class, fn ($job) => $job->event === 'opened');

        // No refund ...
        $this->assertSame('This deposit has an open chargeback (dispute). It is frozen until the bank decides.', app(RefundService::class)->blockReason($hold));

        // ... no transfer by the cron, the admin or a single payout ...
        try {
            app(StripeService::class)->createTransfer($hold, 'manual');
            $this->fail('A frozen deposit was transferred.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('frozen by an open chargeback', $e->getMessage());
        }

        // ... and no withdrawal from the app; the balance shows it apart.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/payment-holds/summary')
            ->assertOk()
            ->assertJsonPath('summary.total_ready_for_transfer_amount', 0)
            ->assertJsonPath('summary.total_disputed_amount', 100);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/payment-holds/withdraw', ['amount' => 10])
            ->assertStatus(400);

        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/transfers'));
    }

    public function test_a_won_dispute_lifts_the_freeze_and_keeps_the_money(): void
    {
        $hold = $this->deposit(100);

        $this->dispute($hold, 'charge.dispute.created', 'needs_response');
        $this->dispute($hold, 'charge.dispute.updated', 'under_review');
        $this->dispute($hold, 'charge.dispute.closed', 'won');

        $this->assertSame('won', Dispute::sole()->outcome);
        $this->assertNull($hold->refresh()->disputed_at);
        $this->assertSame('100.00', $hold->remaining_amount);
        $this->assertSame('0.00', $hold->payment->refresh()->disputed_amount);
        Queue::assertPushed(SendDisputeNotification::class, fn ($job) => $job->event === 'won');
        Queue::assertPushed(SendDisputeNotification::class, 2); // opened + won, not under_review
    }

    public function test_a_lost_dispute_takes_the_money_out_of_the_vault_once(): void
    {
        $hold = $this->deposit(100);

        $this->dispute($hold, 'charge.dispute.created', 'needs_response');
        $this->dispute($hold, 'charge.dispute.closed', 'lost');
        $this->dispute($hold, 'charge.dispute.closed', 'lost', ['id' => 'dp_test_1']); // replayed delivery

        $dispute = Dispute::sole();
        $this->assertSame('lost', $dispute->outcome);
        $this->assertSame('100.00', $dispute->vault_amount);
        $this->assertFalse($dispute->needs_attention);

        $hold->refresh();
        $this->assertSame('0.00', $hold->remaining_amount);
        $this->assertNull($hold->disputed_at);
        $this->assertSame('refunded', $hold->status, 'nothing left anywhere');
        $this->assertSame('100.00', $hold->payment->refresh()->disputed_amount, 'counted once');
        $this->assertSame(0, Artisan::call('refunds:audit'));
        $this->assertSame('This deposit has already been fully refunded.', app(RefundService::class)->blockReason($hold));
        Queue::assertPushed(SendDisputeNotification::class, fn ($job) => $job->event === 'lost');
    }

    public function test_a_partial_lost_dispute_leaves_the_rest_usable(): void
    {
        $hold = $this->deposit(100);

        $this->dispute($hold, 'charge.dispute.closed', 'lost', ['amount' => 4000]);

        $hold->refresh();
        $this->assertSame('60.00', $hold->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertNull($hold->disputed_at);
        $this->assertSame(0, Artisan::call('refunds:audit'));

        // Refund capacity is what is left on the charge: 100 − 40 lost.
        $this->actingAs(User::factory()->create(['role' => 'admin', 'email' => 'megan.carter@example.com']))
            ->getJson(route('admin.payment-holds.refund-preview', $hold->id))
            ->assertJsonPath('money.max_refundable', 60);
    }

    public function test_a_lost_dispute_on_withdrawn_money_is_flagged_for_the_admin(): void
    {
        $hold = $this->deposit(100, ['remaining_amount' => 30, 'status' => 'partial_transferred']);
        Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $this->user->id, 'amount' => 70, 'status' => 'completed']);

        $this->dispute($hold, 'charge.dispute.closed', 'lost');

        $dispute = Dispute::sole();
        $this->assertSame('30.00', $dispute->vault_amount);
        $this->assertTrue($dispute->needs_attention);
        $this->assertStringContainsString('$70.00 of this chargeback was already withdrawn', $dispute->note);
        $this->assertSame('0.00', $hold->refresh()->remaining_amount);
        $this->assertSame('transferred', $hold->status);
        $this->assertSame(0, Artisan::call('refunds:audit'));
    }

    public function test_an_old_api_version_dispute_is_matched_through_its_charge(): void
    {
        $hold = $this->deposit(50);
        $this->stripe->respond('GET /v1/charges/ch_old', ['id' => 'ch_old', 'object' => 'charge', 'payment_intent' => $hold->payment->payment_intent_id]);

        $this->dispute($hold, 'charge.dispute.created', 'needs_response', ['payment_intent' => null, 'charge' => 'ch_old']);

        $this->assertNotNull($hold->refresh()->disputed_at);
    }

    public function test_a_dispute_on_an_unknown_payment_is_ignored(): void
    {
        $hold = $this->deposit(50);
        $this->stripe->respond('GET /v1/invoice_payments', ['object' => 'list', 'has_more' => false, 'data' => []]); // not a subscription either

        $this->dispute($hold, 'charge.dispute.created', 'needs_response', ['payment_intent' => 'pi_not_ours']);

        $this->assertSame(0, Dispute::count());
        $this->assertNull($hold->refresh()->disputed_at);
    }

    public function test_admin_email_and_refunds_page_show_the_chargeback(): void
    {
        $hold = $this->deposit(80);
        $this->dispute($hold, 'charge.dispute.created', 'needs_response');

        $mail = new AdminDisputeMail(Dispute::sole(), 'opened');
        $this->assertSame('[Admin] URGENT chargeback opened: $80.00 for Rachel Turner', $mail->envelope()->subject);
        $mail->assertSeeInHtml('This deposit is now frozen');

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'megan.carter@example.com']);
        $this->actingAs($admin)->get(route('admin.refunds.index'))
            ->assertOk()
            ->assertSee('Chargebacks')
            ->assertSee('Open · deposit frozen');
        $this->actingAs($admin)->get(route('admin.payment-holds.index'))
            ->assertOk()
            ->assertSee('Disputed · frozen');
        $this->actingAs($admin)->get(route('admin.users.show', $this->user))
            ->assertOk()
            ->assertSee('1 open chargeback(s), deposit frozen');

        Mail::fake();
        (new SendDisputeNotification(Dispute::sole(), 'opened'))->handle();
        Mail::assertSent(AdminDisputeMail::class, fn ($m) => $m->hasTo('admin@example.com'));
    }
}
