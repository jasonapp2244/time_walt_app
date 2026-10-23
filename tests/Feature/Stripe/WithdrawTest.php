<?php

namespace Tests\Feature\Stripe;

use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * POST /api/payment-holds/withdraw — the user-facing money path. Stripe is
 * replaced at the HTTP layer, so the controller's real SDK calls run.
 */
class WithdrawTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        Queue::fake();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function connectAccount(): void
    {
        StripeConnectAccount::create([
            'user_id' => $this->user->id,
            'connect_account_id' => 'acct_fake_dest',
            'status' => 'verified',
        ]);
    }

    private function readyHold(float $amount, array $attributes = []): PaymentHold
    {
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'amount' => $amount,
            'stripe_data' => ['latest_charge' => 'ch_deposit_'.uniqid()],
        ]);

        return PaymentHold::factory()->create(array_merge([
            'payment_id' => $payment->id,
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

    private function withdraw(float $amount)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/payment-holds/withdraw', ['amount' => $amount]);
    }

    public function test_full_withdrawal_empties_the_hold_and_records_a_completed_transfer(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(100);
        $this->stripeAccepts('tr_full');

        $this->withdraw(100)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed_amount', 100);

        $hold->refresh();
        $this->assertSame('transferred', $hold->status);
        $this->assertEquals(0, $hold->remaining_amount);
        $this->assertNotNull($hold->transferred_at);

        $transfer = Transfer::sole();
        $this->assertSame('completed', $transfer->status);
        $this->assertSame('user_requested', $transfer->transfer_type);
        $this->assertSame('tr_full', $transfer->stripe_transfer_id);

        $sent = $this->stripe->requestsTo('POST', '/v1/transfers')[0]['params'];
        $this->assertSame(10000, $sent['amount']);
        $this->assertSame('acct_fake_dest', $sent['destination']);
        $this->assertSame($hold->payment->stripe_data['latest_charge'], $sent['source_transaction']);

        // A crashed request retried with this key gets the same transfer back.
        $this->assertSame("tv-hold-{$hold->id}-10000-10000-0", $this->stripe->idempotencyKey($this->stripe->requestsTo('POST', '/v1/transfers')[0]));
    }

    public function test_partial_withdrawal_leaves_the_rest_of_the_hold_available(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(100);
        $this->stripeAccepts('tr_partial');

        $this->withdraw(30)->assertOk();

        $hold->refresh();
        $this->assertSame('partial_transferred', $hold->status);
        $this->assertEquals(70, $hold->remaining_amount);
        $this->assertNull($hold->transferred_at);
        $this->assertSame('user_requested_partial', Transfer::sole()->transfer_type);
    }

    public function test_a_withdrawal_drains_the_oldest_hold_first_and_spans_holds(): void
    {
        $this->connectAccount();
        $newer = $this->readyHold(50, ['hold_end_at' => now()->subHour()]);
        $older = $this->readyHold(40, ['hold_end_at' => now()->subDays(2)]);
        $this->stripeAccepts('tr_one');
        $this->stripeAccepts('tr_two');

        $this->withdraw(60)
            ->assertOk()
            ->assertJsonPath('data.total_transfers', 2);

        $this->assertEquals(0, $older->refresh()->remaining_amount);
        $this->assertSame('transferred', $older->status);
        $this->assertEquals(30, $newer->refresh()->remaining_amount);
        $this->assertSame('partial_transferred', $newer->status);

        $amounts = array_column(array_column($this->stripe->requestsTo('POST', '/v1/transfers'), 'params'), 'amount');
        $this->assertSame([4000, 2000], $amounts);
    }

    public function test_it_refuses_more_than_the_available_balance_without_calling_stripe(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(50);

        $this->withdraw(50.01)
            ->assertStatus(400)
            ->assertJsonPath('data.available_amount', 50);

        $this->assertSame([], $this->stripe->requests);
        $this->assertEquals(50, $hold->refresh()->remaining_amount);
        $this->assertSame(0, Transfer::count());
    }

    public function test_funds_still_inside_the_hold_period_cannot_be_withdrawn(): void
    {
        $this->connectAccount();
        $this->readyHold(100, ['status' => 'holding', 'hold_end_at' => now()->addDay()]);

        $this->withdraw(10)
            ->assertStatus(400)
            ->assertJsonPath('message', 'No funds available for withdrawal. Hold period not completed yet.');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_it_requires_a_bank_account_before_sending_money(): void
    {
        $this->readyHold(100);

        $this->withdraw(10)
            ->assertStatus(400)
            ->assertJsonPath('message', 'No bank account found. Please add your bank details first.');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_stripe_rejection_on_the_only_transfer_changes_nothing(): void
    {
        $this->connectAccount();
        $hold = $this->readyHold(100);
        $this->stripe->respond('POST /v1/transfers', [
            'error' => ['type' => 'invalid_request_error', 'message' => 'Insufficient funds'],
        ], 400);

        $this->withdraw(100)->assertStatus(500);

        $this->assertEquals(100, $hold->refresh()->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertSame(0, Transfer::count());
    }

    public function test_money_already_sent_stays_recorded_when_a_later_transfer_is_rejected(): void
    {
        $this->connectAccount();
        $older = $this->readyHold(40, ['hold_end_at' => now()->subDays(2)]);
        $newer = $this->readyHold(50, ['hold_end_at' => now()->subHour()]);
        $this->stripeAccepts('tr_sent');
        $this->stripe->respond('POST /v1/transfers', [
            'error' => ['type' => 'invalid_request_error', 'message' => 'Insufficient funds'],
        ], 400);

        $this->withdraw(60)
            ->assertOk()
            ->assertJsonPath('data.processed_amount', 40)
            ->assertJsonPath('data.partial', true)
            ->assertJsonPath('data.summary.difference', 20);

        // The first transfer left the platform account; losing its record would
        // let the user withdraw the same 40 again.
        $sent = Transfer::sole();
        $this->assertSame('tr_sent', $sent->stripe_transfer_id);
        $this->assertSame($older->id, $sent->hold_id);
        $this->assertEquals(0, $older->refresh()->remaining_amount);
        $this->assertSame('transferred', $older->status);

        $this->assertEquals(50, $newer->refresh()->remaining_amount);
        $this->assertSame('ready_for_transfer', $newer->status);
    }
}
