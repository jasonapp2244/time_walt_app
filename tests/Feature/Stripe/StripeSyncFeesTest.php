<?php

namespace Tests\Feature\Stripe;

use App\Console\Commands\StripeSyncFees;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeFee;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * stripe:sync-fees copies the fees Stripe kept (from its balance
 * transactions) into stripe_fees and links each one to its deposit,
 * subscription payment or chargeback. Stripe is only read.
 */
class StripeSyncFeesTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    private Payment $payment;

    private SubscriptionInvoice $invoice;

    private Dispute $dispute;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Cache::forget(StripeSyncFees::CACHE_KEY);

        $this->user = User::factory()->create(['full_name' => 'Ryan Mitchell', 'email' => 'ryan.mitchell@example.com']);
        $this->payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 2000, 'payment_intent_id' => 'pi_deposit']);
        $hold = PaymentHold::factory()->create(['payment_id' => $this->payment->id, 'user_id' => $this->user->id, 'amount' => 2000, 'remaining_amount' => 2000]);
        $this->invoice = SubscriptionInvoice::factory()->create(['user_id' => $this->user->id, 'amount' => 4.99, 'stripe_invoice_id' => 'in_subscription']);
        $this->dispute = Dispute::unguarded(fn () => Dispute::create([
            'payment_id' => $this->payment->id, 'payment_hold_id' => $hold->id, 'user_id' => $this->user->id,
            'stripe_dispute_id' => 'dp_known', 'amount' => 10, 'vault_amount' => 0, 'currency' => 'usd',
            'outcome' => 'open', 'stripe_status' => 'needs_response', 'needs_attention' => true,
        ]));
    }

    private function stripeHas(): void
    {
        $list = fn (string $url, array $data) => ['object' => 'list', 'has_more' => false, 'url' => $url, 'data' => $data];
        $txn = fn (string $id, string $type, int $amount, int $fee, ?array $source, ?string $description = null) => [
            'id' => $id, 'object' => 'balance_transaction', 'type' => $type, 'amount' => $amount, 'fee' => $fee,
            'net' => $amount - $fee, 'currency' => 'usd', 'created' => 1795241460, 'description' => $description,
            'fee_details' => $fee ? [['type' => 'stripe_fee', 'amount' => $fee, 'currency' => 'usd', 'description' => 'Stripe processing fees']] : [],
            'source' => $source,
        ];

        $this->stripe->respond('GET /v1/balance_transactions', $list('/v1/balance_transactions', [
            $txn('txn_deposit', 'charge', 200000, 5830, ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_deposit']),
            $txn('txn_subscription', 'charge', 499, 44, ['id' => 'ch_2', 'object' => 'charge', 'payment_intent' => 'pi_subscription', 'customer' => 'cus_x']),
            $txn('txn_dispute', 'adjustment', -1000, 1500, ['id' => 'dp_known', 'object' => 'dispute', 'payment_intent' => 'pi_deposit'], 'Chargeback withdrawal'),
            $txn('txn_connect', 'stripe_fee', -200, 0, null, 'Connect (2026-11-01): Active account billing'),
            $txn('txn_unknown', 'charge', 1500, 74, ['id' => 'ch_3', 'object' => 'charge', 'payment_intent' => 'pi_not_in_app', 'metadata' => ['user_id' => (string) $this->user->id]]),
            $txn('txn_billing', 'charge', 1500, 74, ['id' => 'ch_4', 'object' => 'charge', 'payment_intent' => 'pi_old_subscription', 'description' => 'Subscription creation']),
            $txn('txn_withdrawal', 'transfer', -5000, 0, ['id' => 'tr_1', 'object' => 'transfer']),
        ]));
        // Asked only for charges the app has no deposit for: the subscription one, then the unknown one.
        $this->stripe->respond('GET /v1/invoice_payments', $list('/v1/invoice_payments', [
            ['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_subscription'],
        ]));
        $this->stripe->respond('GET /v1/invoice_payments', $list('/v1/invoice_payments', []));
        $this->stripe->respond('GET /v1/invoice_payments', $list('/v1/invoice_payments', []));
    }

    public function test_apply_saves_every_fee_linked_to_its_record_and_a_rerun_adds_nothing(): void
    {
        $this->stripeHas();
        $this->artisan('stripe:sync-fees', ['--since' => '2026-01-01', '--apply' => true])->assertSuccessful();

        $this->assertSame(6, StripeFee::count(), 'the withdrawal has no fee and is skipped');

        $deposit = StripeFee::where('category', 'deposit')->where('payment_id', $this->payment->id)->sole();
        $this->assertSame('58.30', $deposit->fee);
        $this->assertSame($this->user->id, $deposit->user_id);
        $this->assertSame('txn_deposit', $deposit->balance_transaction_id);

        $subscription = StripeFee::where('category', 'subscription')->whereNotNull('subscription_invoice_id')->sole();
        $this->assertSame($this->invoice->id, $subscription->subscription_invoice_id);
        $this->assertSame('0.44', $subscription->fee);

        $chargeback = StripeFee::where('category', 'chargeback')->sole();
        $this->assertSame($this->dispute->id, $chargeback->dispute_id);
        $this->assertSame('15.00', $chargeback->fee);

        $platform = StripeFee::where('category', 'platform')->sole();
        $this->assertNull($platform->user_id);
        $this->assertSame('2.00', $platform->fee, 'a stripe_fee line is the fee itself');

        $unknown = StripeFee::where('balance_transaction_id_index', StripeFee::blindIndex('txn_unknown'))->sole();
        $this->assertNull($unknown->payment_id);
        $this->assertSame($this->user->id, $unknown->user_id, 'a fee the app has no deposit for is still kept');

        $billing = StripeFee::where('balance_transaction_id_index', StripeFee::blindIndex('txn_billing'))->sole();
        $this->assertSame('subscription', $billing->category, 'named by Stripe Billing');
        $this->assertNull($billing->user_id);

        $this->assertSame(77.22, round((float) StripeFee::sum('fee'), 2));
        $this->assertTrue(Cache::get(StripeSyncFees::CACHE_KEY)['ok']);

        // Running again saves nothing new.
        $this->stripeHas();
        $this->artisan('stripe:sync-fees', ['--since' => '2026-01-01', '--apply' => true])->assertSuccessful();
        $this->assertSame(6, StripeFee::count());
        $this->assertSame(0, Cache::get(StripeSyncFees::CACHE_KEY)['new']);
        $this->assertSame(77.22, round((float) StripeFee::sum('fee'), 2));
    }

    public function test_it_only_reads_stripe(): void
    {
        $this->stripeHas();
        $this->artisan('stripe:sync-fees', ['--apply' => true])->assertSuccessful();

        $this->assertNotEmpty($this->stripe->requests);
        foreach ($this->stripe->requests as $request) {
            $this->assertSame('GET', $request['method']);
        }
        $this->assertSame('2000.00', PaymentHold::sole()->remaining_amount, 'money tables are not touched');
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->stripeHas();

        $this->artisan('stripe:sync-fees')->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertSame(0, StripeFee::count());
        $this->assertNull(Cache::get(StripeSyncFees::CACHE_KEY));
    }

    public function test_a_stripe_outage_is_reported_and_not_marked_ok(): void
    {
        $this->stripe->respond('GET /v1/balance_transactions', ['error' => ['message' => 'Stripe is down', 'type' => 'api_error']], 500);

        $this->artisan('stripe:sync-fees', ['--apply' => true])->assertFailed();

        $this->assertSame(0, StripeFee::count());
        $this->assertFalse(Cache::get(StripeSyncFees::CACHE_KEY)['ok']);
    }

    public function test_bad_since_is_rejected(): void
    {
        $this->artisan('stripe:sync-fees', ['--since' => 'not a date'])->assertFailed();
    }

    public function test_it_runs_every_hour(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('stripe:sync-fees --apply')->assertSuccessful();
    }
}
