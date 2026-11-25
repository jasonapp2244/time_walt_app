<?php

namespace Tests\Feature\Stripe;

use App\Console\Commands\StripeSyncMissed;
use App\Jobs\SendRefundIssuedNotification;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * stripe:sync-missed reads Stripe and records what the webhooks missed (for
 * example a deposit refunded in the Stripe Dashboard before the refund
 * webhooks existed, still "Holding" in the admin), so the ledger matches Stripe.
 */
class StripeSyncMissedTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    private PaymentHold $refundedHold;

    private PaymentHold $disputedHold;

    private Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();
        Cache::forget(StripeSyncMissed::CACHE_KEY);

        $this->user = User::factory()->create(['full_name' => 'Ryan Mitchell', 'email' => 'ryan.mitchell@example.com']);

        // Refunded in Stripe on 2026-11-21; the app still shows $250 holding.
        $payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 250, 'payment_intent_id' => 'pi_refunded']);
        $this->refundedHold = PaymentHold::factory()->create([
            'payment_id' => $payment->id, 'user_id' => $this->user->id, 'amount' => 250, 'remaining_amount' => 250,
            'status' => 'holding', 'hold_start_at' => now()->subDays(14), 'hold_end_at' => now()->addDays(60),
        ]);

        // A chargeback lost in Stripe that the app never heard of.
        $payment2 = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 80, 'payment_intent_id' => 'pi_disputed']);
        $this->disputedHold = PaymentHold::factory()->create([
            'payment_id' => $payment2->id, 'user_id' => $this->user->id, 'amount' => 80, 'remaining_amount' => 80,
            'status' => 'ready_for_transfer', 'hold_start_at' => now()->subDays(10), 'hold_end_at' => now()->subDay(),
        ]);

        // A withdrawal reversed by hand in Stripe.
        $payment3 = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 60, 'payment_intent_id' => 'pi_withdrawn']);
        $hold3 = PaymentHold::factory()->create([
            'payment_id' => $payment3->id, 'user_id' => $this->user->id, 'amount' => 60, 'remaining_amount' => 0,
            'status' => 'transferred', 'hold_start_at' => now()->subDays(10), 'hold_end_at' => now()->subDays(5),
        ]);
        $this->transfer = Transfer::factory()->create(['hold_id' => $hold3->id, 'user_id' => $this->user->id, 'amount' => 60, 'stripe_transfer_id' => 'tr_reversed_by_hand']);
    }

    private function stripeHas(): void
    {
        $list = fn (string $url, array $data) => ['object' => 'list', 'has_more' => false, 'url' => $url, 'data' => $data];

        $this->stripe->respond('GET /v1/refunds', $list('/v1/refunds', [
            ['id' => 're_old_dashboard', 'object' => 'refund', 'amount' => 25000, 'currency' => 'usd', 'payment_intent' => 'pi_refunded', 'status' => 'succeeded', 'metadata' => [], 'created' => 1795241460],
            ['id' => 're_failed_x', 'object' => 'refund', 'amount' => 500, 'currency' => 'usd', 'payment_intent' => 'pi_refunded', 'status' => 'failed', 'metadata' => []],
        ]));
        $this->stripe->respond('GET /v1/disputes', $list('/v1/disputes', [
            ['id' => 'dp_missed', 'object' => 'dispute', 'amount' => 8000, 'currency' => 'usd', 'payment_intent' => 'pi_disputed', 'status' => 'lost', 'reason' => 'fraudulent', 'created' => 1795241460],
        ]));
        $this->stripe->respond('GET /v1/transfers', $list('/v1/transfers', [
            ['id' => 'tr_reversed_by_hand', 'object' => 'transfer', 'amount' => 6000, 'amount_reversed' => 2500,
                'reversals' => ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'trr_1', 'object' => 'transfer_reversal', 'amount' => 2500, 'metadata' => []]]]],
            ['id' => 'tr_someone_else', 'object' => 'transfer', 'amount' => 1000, 'amount_reversed' => 1000, 'reversals' => ['object' => 'list', 'has_more' => false, 'data' => []]],
        ]));
        $this->stripe->respond('GET /v1/payment_intents', $list('/v1/payment_intents', [
            ['id' => 'pi_refunded', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 25000, 'metadata' => ['user_id' => (string) $this->user->id]],
            ['id' => 'pi_not_in_app_12', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1500, 'amount_received' => 1500, 'metadata' => ['user_id' => (string) $this->user->id], 'created' => 1795241460],
            ['id' => 'pi_subscription', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 499, 'metadata' => []],
        ]));
    }

    public function test_dry_run_lists_what_is_missing_and_changes_nothing(): void
    {
        $this->stripeHas();

        $this->artisan('stripe:sync-missed')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('MISSING: refund …ashboard $250.00 (2026-11-21 06:11 UTC) on deposit #'.str_pad((string) $this->refundedHold->id, 5, '0', STR_PAD_LEFT))
            ->expectsOutputToContain('MISSING: chargeback …p_missed $80.00')
            ->expectsOutputToContain('reversed by hand in Stripe: $25.00')
            ->expectsOutputToContain('NOT IN APP (check by hand): deposit pi_…n_app_12 $15.00')
            ->assertSuccessful();

        $this->assertSame(0, Refund::count());
        $this->assertSame(0, Dispute::count());
        $this->assertSame('250.00', $this->refundedHold->refresh()->remaining_amount);
        $this->assertSame('0.00', $this->transfer->refresh()->manual_reversed_amount);
        $this->assertNull(Cache::get(StripeSyncMissed::CACHE_KEY), 'a dry run is not reported as the nightly check');
    }

    public function test_apply_records_everything_once_and_the_ledger_matches(): void
    {
        $this->stripeHas();
        $this->artisan('stripe:sync-missed', ['--apply' => true, '--notify' => true])->assertSuccessful();

        // The refund: vault emptied, dated when Stripe made it, no late email (it is days old).
        $refund = Refund::sole();
        $this->assertSame('stripe_dashboard', $refund->source);
        $this->assertSame('2026-11-21', $refund->refunded_at->utc()->toDateString());
        $this->assertSame('0.00', $this->refundedHold->refresh()->remaining_amount);
        $this->assertSame('refunded', $this->refundedHold->status);
        Queue::assertNotPushed(SendRefundIssuedNotification::class);

        // The chargeback: taken out of the vault.
        $this->assertSame('lost', Dispute::sole()->outcome);
        $this->assertSame('0.00', $this->disputedHold->refresh()->remaining_amount);

        // The hand reversal: back in the user's vault.
        $this->assertSame('25.00', $this->transfer->refresh()->manual_reversed_amount);

        $last = Cache::get(StripeSyncMissed::CACHE_KEY);
        $this->assertTrue($last['ok']);
        $this->assertSame(3, $last['fixed']);
        $this->assertCount(1, $last['missing_deposits']);

        // Ledger and vaults agree, and a second run finds nothing new.
        $this->assertSame([], app(LedgerService::class)->mismatches());
        $this->stripeHas();
        $this->artisan('stripe:sync-missed', ['--apply' => true])->assertSuccessful();
        $this->assertSame(0, Cache::get(StripeSyncMissed::CACHE_KEY)['missing']);
        $this->assertSame(1, Refund::count());
        $this->assertSame('25.00', $this->transfer->refresh()->manual_reversed_amount);

        $this->artisan('refunds:audit')->assertSuccessful();
    }

    public function test_a_stripe_outage_is_reported_and_not_marked_ok(): void
    {
        $this->stripe->respond('GET /v1/refunds', ['error' => ['message' => 'Stripe is down', 'type' => 'api_error']], 500);

        $this->artisan('stripe:sync-missed', ['--apply' => true])->assertFailed();

        $this->assertFalse(Cache::get(StripeSyncMissed::CACHE_KEY)['ok']);
        $this->assertSame('250.00', $this->refundedHold->refresh()->remaining_amount);
    }

    public function test_a_recent_missed_refund_still_emails_with_notify(): void
    {
        $list = fn (string $url, array $data = []) => ['object' => 'list', 'has_more' => false, 'url' => $url, 'data' => $data];
        $this->stripe->respond('GET /v1/refunds', $list('/v1/refunds', [
            ['id' => 're_yesterday', 'object' => 'refund', 'amount' => 1000, 'currency' => 'usd', 'payment_intent' => 'pi_refunded', 'status' => 'succeeded', 'metadata' => [], 'created' => now()->subDay()->timestamp],
        ]));
        $this->stripe->respond('GET /v1/disputes', $list('/v1/disputes'));
        $this->stripe->respond('GET /v1/transfers', $list('/v1/transfers'));
        $this->stripe->respond('GET /v1/payment_intents', $list('/v1/payment_intents'));

        $this->artisan('stripe:sync-missed', ['--apply' => true, '--notify' => true])->assertSuccessful();

        Queue::assertPushed(SendRefundIssuedNotification::class);
        $this->assertSame('240.00', $this->refundedHold->refresh()->remaining_amount, 'a partial refund leaves the rest in the vault');
    }

    public function test_it_runs_every_night(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('stripe:sync-missed --apply --notify')->assertSuccessful();
    }
}
