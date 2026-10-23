<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendPayoutRequestNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * StripeService::createTransfer() — the transfer used by the admin panel, the
 * admin API, request-payout and the auto-transfer cron. Stripe is faked at the
 * SDK HTTP layer, so the real \Stripe\Transfer::create call runs.
 */
class CreateTransferTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();

        $this->user = User::factory()->create();
    }

    private function connectAccount(array $attributes = []): StripeConnectAccount
    {
        return StripeConnectAccount::create(array_merge([
            'user_id' => $this->user->id,
            'connect_account_id' => 'acct_fake_dest',
            'status' => 'verified',
            'payouts_enabled' => true,
        ], $attributes));
    }

    private function readyHold(float $amount, array $attributes = [], array $payment = []): PaymentHold
    {
        $deposit = Payment::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'amount' => $amount,
            'stripe_data' => ['latest_charge' => 'ch_deposit_fake'],
        ], $payment));

        return PaymentHold::factory()->create(array_merge([
            'payment_id' => $deposit->id,
            'user_id' => $this->user->id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'hold_start_at' => now()->subDays(3),
            'hold_end_at' => now()->subDay(),
            'status' => 'ready_for_transfer',
        ], $attributes));
    }

    private function stripeAccepts(string $id): void
    {
        $this->stripe->respond('POST /v1/transfers', ['id' => $id, 'object' => 'transfer']);
    }

    private function stripeRejects(string $message): void
    {
        $this->stripe->respond('POST /v1/transfers', [
            'error' => ['type' => 'invalid_request_error', 'message' => $message],
        ], 400);
    }

    private function service(): StripeService
    {
        return app(StripeService::class);
    }

    public function test_it_sends_the_remaining_amount_to_the_users_connect_account(): void
    {
        $this->connectAccount();
        $bank = UserBankAccount::factory()->create(['user_id' => $this->user->id]);
        $hold = $this->readyHold(120.50);
        $this->stripeAccepts('tr_full');

        $transfer = $this->service()->createTransfer($hold, 'manual');

        $sent = $this->stripe->requestsTo('POST', '/v1/transfers');
        $this->assertCount(1, $sent);
        $this->assertSame(12050, $sent[0]['params']['amount']);
        $this->assertSame('usd', $sent[0]['params']['currency']);
        $this->assertSame('acct_fake_dest', $sent[0]['params']['destination']);
        $this->assertSame('ch_deposit_fake', $sent[0]['params']['source_transaction']);
        $this->assertSame($hold->id, (int) $sent[0]['params']['metadata']['hold_id']);

        $this->assertSame('tr_full', $transfer->stripe_transfer_id);
        $this->assertSame('pending', $transfer->status);
        $this->assertSame('manual', $transfer->transfer_type);
        $this->assertSame('120.50', $transfer->amount);
        $this->assertSame($bank->id, $transfer->bank_account_id);
        $this->assertNull($transfer->admin_id);

        $hold->refresh();
        $this->assertSame('transferred', $hold->status);
        $this->assertEquals(0, $hold->remaining_amount);
        $this->assertNotNull($hold->transferred_at);
    }

    public function test_only_what_is_left_of_a_partly_withdrawn_hold_is_sent(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(100, ['remaining_amount' => 40, 'status' => 'partial_transferred']);
        $this->stripeAccepts('tr_rest');

        $transfer = $this->service()->createTransfer($hold, 'cron');

        $this->assertSame(4000, $this->stripe->requestsTo('POST', '/v1/transfers')[0]['params']['amount']);
        $this->assertSame('40.00', $transfer->amount);
        $this->assertSame('transferred', $hold->refresh()->status);
        $this->assertEquals(0, $hold->remaining_amount);
    }

    public function test_the_currency_follows_the_deposit(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(30, [], ['currency' => 'EUR']);
        $this->stripeAccepts('tr_eur');

        $transfer = $this->service()->createTransfer($hold);

        $this->assertSame('eur', $this->stripe->requestsTo('POST', '/v1/transfers')[0]['params']['currency']);
        $this->assertSame('eur', $transfer->currency);
    }

    public function test_without_a_connect_account_nothing_is_sent(): void
    {
        $hold = $this->readyHold(50);

        try {
            $this->service()->createTransfer($hold);
            $this->fail('Expected the transfer to be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Connect account not found', $e->getMessage());
        }

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame(0, Transfer::count());
        $this->assertSame('ready_for_transfer', $hold->refresh()->status);
        $this->assertEquals(50, $hold->remaining_amount);
    }

    public function test_an_account_without_payouts_enabled_is_refused(): void
    {
        $this->connectAccount(['payouts_enabled' => false, 'status' => 'pending']);
        $hold = $this->readyHold(50);

        $this->expectExceptionMessage('does not have payouts enabled');

        try {
            $this->service()->createTransfer($hold);
        } finally {
            $this->assertSame([], $this->stripe->requests);
            $this->assertSame(0, Transfer::count());
        }
    }

    public function test_a_stripe_rejection_records_nothing_and_leaves_the_hold_intact(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(75);
        $this->stripeRejects('Insufficient funds in Stripe account.');

        try {
            $this->service()->createTransfer($hold);
            $this->fail('Expected Stripe to reject the transfer.');
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->assertStringContainsString('Insufficient funds', $e->getMessage());
        }

        $this->assertSame(0, Transfer::count());
        $hold->refresh();
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertEquals(75, $hold->remaining_amount);
    }

    /**
     * Two requests load the same hold; the first one empties it. The second
     * still holds the stale model and must not pay the same money out again.
     */
    public function test_a_hold_emptied_by_a_concurrent_request_is_not_paid_out_twice(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(60);
        $stale = PaymentHold::find($hold->id);
        $this->stripeAccepts('tr_first');

        $this->service()->createTransfer($hold);

        try {
            $this->service()->createTransfer($stale);
            $this->fail('Expected the second transfer to be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('nothing left to transfer', $e->getMessage());
        }

        $this->assertCount(1, $this->stripe->requestsTo('POST', '/v1/transfers'));
        $this->assertSame(1, Transfer::count());
        $this->assertEquals(0, $hold->refresh()->remaining_amount);
    }

    public function test_a_hold_drained_by_withdraw_is_not_paid_out_again(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(60);
        $stale = PaymentHold::find($hold->id);
        $hold->update(['remaining_amount' => 0, 'status' => 'transferred']);

        $this->expectExceptionMessage('nothing left to transfer');

        try {
            $this->service()->createTransfer($stale);
        } finally {
            $this->assertSame([], $this->stripe->requests);
        }
    }

    public function test_a_transfer_stripe_sent_but_the_database_could_not_record_is_logged_for_reconciliation(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(25);
        $this->stripeAccepts('tr_orphan');
        Log::spy();

        Transfer::creating(function () {
            throw new \RuntimeException('Deadlock found when trying to get lock');
        });

        try {
            $this->service()->createTransfer($hold);
            $this->fail('Expected the database failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Deadlock', $e->getMessage());
        } finally {
            Transfer::flushEventListeners();
        }

        Log::shouldHaveReceived('critical')->withArgs(
            fn (string $message, array $context) => str_contains($message, 'could not be recorded')
                && $context['stripe_transfer_id'] === 'tr_orphan'
                && $context['hold_id'] === $hold->id
        )->once();
        $this->assertEquals(25, $hold->refresh()->remaining_amount);
    }

    public function test_a_retry_after_an_unrecorded_transfer_reuses_the_idempotency_key(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(25);
        $this->stripeAccepts('tr_once');
        $this->stripeAccepts('tr_once'); // Stripe answers a repeated key with the same transfer.

        Transfer::creating(fn () => throw new \RuntimeException('Lost connection'));

        try {
            $this->service()->createTransfer($hold);
        } catch (\RuntimeException) {
        } finally {
            Transfer::flushEventListeners();
        }

        $this->service()->createTransfer($hold->refresh());

        [$first, $retry] = $this->stripe->requestsTo('POST', '/v1/transfers');
        $this->assertNotNull($this->stripe->idempotencyKey($first));
        $this->assertSame($this->stripe->idempotencyKey($first), $this->stripe->idempotencyKey($retry));
        $this->assertSame('tr_once', Transfer::sole()->stripe_transfer_id);
    }

    public function test_after_a_stripe_rejection_the_retry_is_a_fresh_request(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(25);
        $this->stripeRejects('Insufficient funds');
        $this->stripeAccepts('tr_later');

        try {
            $this->service()->createTransfer($hold);
        } catch (\Stripe\Exception\ApiErrorException) {
        }

        $this->service()->createTransfer($hold->refresh());

        [$rejected, $retry] = $this->stripe->requestsTo('POST', '/v1/transfers');
        $this->assertNotSame($this->stripe->idempotencyKey($rejected), $this->stripe->idempotencyKey($retry));
        $this->assertSame('tr_later', Transfer::sole()->stripe_transfer_id);
    }

    public function test_the_admin_panel_executes_a_transfer_and_records_the_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->connectAccount();
        $hold = $this->readyHold(80);
        $this->stripeAccepts('tr_admin');

        $this->actingAs($admin)
            ->post("/admin/transfers/{$hold->id}/execute")
            ->assertRedirect()
            ->assertSessionHas('success');

        $transfer = Transfer::sole();
        $this->assertSame($admin->id, $transfer->admin_id);
        $this->assertSame('manual', $transfer->transfer_type);
        $this->assertSame('transferred', $hold->refresh()->status);
    }

    public function test_the_admin_panel_refuses_a_hold_that_is_not_ready(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->connectAccount();
        $hold = $this->readyHold(80, ['status' => 'holding', 'hold_end_at' => now()->addDay()]);

        $this->actingAs($admin)
            ->post("/admin/transfers/{$hold->id}/execute")
            ->assertSessionHas('error');

        $this->assertSame([], $this->stripe->requests);
        $this->assertSame(0, Transfer::count());
    }

    public function test_request_payout_transfers_the_hold_and_notifies(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(45);
        $this->stripeAccepts('tr_payout');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/payment-holds/{$hold->id}/request-payout")
            ->assertOk()
            ->assertJsonPath('data.stripe_transfer_id', 'tr_payout')
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('user_requested', Transfer::sole()->transfer_type);
        Queue::assertPushed(SendPayoutRequestNotification::class);
    }

    public function test_request_payout_reports_a_stripe_failure_without_touching_the_hold(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(45);
        $this->stripeRejects('Insufficient funds in Stripe account.');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/payment-holds/{$hold->id}/request-payout")
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Transfer::count());
        $this->assertEquals(45, $hold->refresh()->remaining_amount);
        Queue::assertNotPushed(SendPayoutRequestNotification::class);
    }

    public function test_the_auto_transfer_cron_pays_ended_holds_and_one_failure_does_not_stop_the_rest(): void
    {
        config(['services.stripe.auto_transfer_enabled' => true]);
        $this->connectAccount();
        $first = $this->readyHold(10, ['status' => 'holding']);
        $second = $this->readyHold(20, ['status' => 'holding']);
        $this->stripeRejects('Insufficient funds in Stripe account.');
        $this->stripeAccepts('tr_cron');

        $this->artisan('check:payment-holds')->assertSuccessful();

        $this->assertSame('ready_for_transfer', $first->refresh()->status);
        $this->assertEquals(10, $first->remaining_amount);
        $this->assertSame('transferred', $second->refresh()->status);
        $this->assertSame('cron', Transfer::sole()->transfer_type);
    }
}
