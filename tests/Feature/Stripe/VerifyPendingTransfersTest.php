<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendTransferCompletedNotification;
use App\Jobs\SendTransferCompletedSummaryNotification;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Stripe\ApiRequestor;
use Tests\Fakes\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * verify:pending-transfers settles transfers that withdraw() left pending.
 * withdraw() already deducted remaining_amount, so success must not deduct
 * again and failure must give the money back to the hold.
 */
class VerifyPendingTransfersTest extends TestCase
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

    /** A hold with $sent of $amount already deducted by withdraw() into a pending transfer. */
    private function pendingTransfer(float $amount, float $sent, string $stripeId): Transfer
    {
        $hold = PaymentHold::factory()->create([
            'user_id' => $this->user->id,
            'amount' => $amount,
            'remaining_amount' => $amount - $sent,
            'hold_end_at' => now()->subDay(),
            'status' => 'ready_for_transfer',
        ]);

        return Transfer::factory()->create([
            'hold_id' => $hold->id,
            'user_id' => $this->user->id,
            'stripe_transfer_id' => $stripeId,
            'amount' => $sent,
            'status' => 'pending',
            'transferred_at' => null,
        ]);
    }

    private function stripeReturns(string $id, array $extra = []): void
    {
        $this->stripe->respond("GET /v1/transfers/{$id}", array_merge(['id' => $id, 'object' => 'transfer'], $extra));
    }

    public function test_a_settled_transfer_completes_and_empties_the_hold_without_deducting_twice(): void
    {
        $transfer = $this->pendingTransfer(100, 100, 'tr_settled');
        $this->stripeReturns('tr_settled');

        $this->artisan('verify:pending-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame('completed', $transfer->status);
        $this->assertNotNull($transfer->transferred_at);

        $hold = $transfer->hold;
        $this->assertSame('transferred', $hold->status);
        $this->assertEquals(0, $hold->remaining_amount);
        $this->assertNotNull($hold->transferred_at);

        Queue::assertPushed(SendTransferCompletedNotification::class);
    }

    public function test_a_settled_partial_transfer_marks_the_hold_partially_transferred(): void
    {
        $transfer = $this->pendingTransfer(100, 30, 'tr_part');
        $this->stripeReturns('tr_part');

        $this->artisan('verify:pending-transfers')->assertSuccessful();

        $hold = $transfer->hold->refresh();
        $this->assertSame('partial_transferred', $hold->status);
        $this->assertEquals(70, $hold->remaining_amount);
        $this->assertNull($hold->transferred_at);
    }

    public function test_a_failed_transfer_gives_the_whole_amount_back_to_the_hold(): void
    {
        $transfer = $this->pendingTransfer(100, 100, 'tr_failed');
        $this->stripeReturns('tr_failed', ['failure_message' => 'Account closed']);

        $this->artisan('verify:pending-transfers')->assertSuccessful();

        $transfer->refresh();
        $this->assertSame('failed', $transfer->status);
        $this->assertSame('Account closed', $transfer->failure_reason);

        $hold = $transfer->hold->refresh();
        $this->assertEquals(100, $hold->remaining_amount);
        $this->assertSame('ready_for_transfer', $hold->status);

        Queue::assertNothingPushed();
    }

    public function test_a_failed_partial_transfer_restores_only_its_own_amount(): void
    {
        // 100 hold: 30 already completed earlier, this 20 then failed; 50 untouched.
        $transfer = $this->pendingTransfer(100, 50, 'tr_failed_part');
        $transfer->update(['amount' => 20]);
        $this->stripeReturns('tr_failed_part', ['failure_message' => 'Bank rejected']);

        $this->artisan('verify:pending-transfers')->assertSuccessful();

        $hold = $transfer->hold->refresh();
        $this->assertEquals(70, $hold->remaining_amount);
        $this->assertSame('partial_transferred', $hold->status);
    }

    public function test_a_stripe_error_leaves_the_transfer_pending_for_the_next_run(): void
    {
        $transfer = $this->pendingTransfer(100, 100, 'tr_missing');
        $this->stripe->respond('GET /v1/transfers/tr_missing', [
            'error' => ['type' => 'invalid_request_error', 'message' => 'No such transfer'],
        ], 404);

        $this->artisan('verify:pending-transfers')->assertSuccessful();

        $this->assertSame('pending', $transfer->refresh()->status);
        $this->assertEquals(0, $transfer->hold->remaining_amount);
    }

    public function test_several_settled_transfers_for_one_user_send_a_single_summary_email(): void
    {
        $this->pendingTransfer(100, 100, 'tr_a');
        $this->pendingTransfer(50, 50, 'tr_b');
        $this->stripeReturns('tr_a');
        $this->stripeReturns('tr_b');

        $this->artisan('verify:pending-transfers')->assertSuccessful();

        $this->assertSame(2, Transfer::where('status', 'completed')->count());
        Queue::assertPushed(SendTransferCompletedSummaryNotification::class, 1);
        Queue::assertNotPushed(SendTransferCompletedNotification::class);
    }
}
