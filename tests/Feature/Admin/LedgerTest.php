<?php

namespace Tests\Feature\Admin;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\SubscriptionInvoice;
use App\Models\Transfer;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The admin ledger lists every money movement from the existing records,
 * with each line's effect on the user's vault and the running balance.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private PaymentHold $holdA;

    private PaymentHold $holdB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Megan Carter', 'email' => 'megan.carter@example.com']);
        $this->user = User::factory()->create(['full_name' => 'Jacob Turner', 'email' => 'jacob.turner@example.com']);
        $day = Carbon::parse('2026-11-01 12:00:00', 'UTC');

        // Deposit A: $100 → $50 withdrawn → $40 refund ($20 from the vault, $20 pulled back) → $30 left.
        $paymentA = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 100, 'paid_at' => $day]);
        $this->holdA = PaymentHold::factory()->create(['payment_id' => $paymentA->id, 'user_id' => $this->user->id, 'amount' => 100, 'remaining_amount' => 30, 'status' => 'partial_transferred']);
        Transfer::factory()->create(['hold_id' => $this->holdA->id, 'user_id' => $this->user->id, 'amount' => 50, 'reversed_amount' => 20, 'status' => 'completed', 'transferred_at' => $day->copy()->addDay()]);
        Refund::factory()->create([
            'payment_id' => $paymentA->id, 'payment_hold_id' => $this->holdA->id, 'user_id' => $this->user->id,
            'amount' => 40, 'vault_amount' => 20, 'reversed_amount' => 20, 'status' => 'succeeded',
            'created_at' => $day->copy()->addDays(2), 'refunded_at' => $day->copy()->addDays(2)->addHour(),
        ]);
        // Failed records are listed with no effect.
        Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 25, 'status' => 'failed', 'paid_at' => null, 'created_at' => $day->copy()->addHours(2)]);
        Transfer::factory()->create(['hold_id' => $this->holdA->id, 'user_id' => $this->user->id, 'amount' => 10, 'status' => 'failed', 'transferred_at' => $day->copy()->addHours(30)]);

        // Deposit B: $200, chargeback lost $50 → $150 left.
        $paymentB = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 200, 'paid_at' => $day->copy()->addDays(3)]);
        $this->holdB = PaymentHold::factory()->create(['payment_id' => $paymentB->id, 'user_id' => $this->user->id, 'amount' => 200, 'remaining_amount' => 150, 'status' => 'holding']);
        Dispute::unguarded(fn () => Dispute::create([
            'payment_id' => $paymentB->id, 'payment_hold_id' => $this->holdB->id, 'user_id' => $this->user->id,
            'stripe_dispute_id' => 'dp_ledger_1', 'amount' => 50, 'vault_amount' => 50, 'currency' => 'usd',
            'outcome' => 'lost', 'stripe_status' => 'lost', 'needs_attention' => false,
            'created_at' => $day->copy()->addDays(4), 'closed_at' => $day->copy()->addDays(5),
        ]));

        // A subscription payment: revenue, not vault money.
        SubscriptionInvoice::factory()->create(['user_id' => $this->user->id, 'amount' => 4.99, 'paid_at' => $day->copy()->addDays(6)]);

        // Someone else's deposit must not leak into this user's view.
        $other = User::factory()->create(['full_name' => 'Laura Bennett', 'email' => 'laura.bennett@example.com']);
        $otherPayment = Payment::factory()->create(['user_id' => $other->id, 'amount' => 999, 'paid_at' => $day]);
        PaymentHold::factory()->create(['payment_id' => $otherPayment->id, 'user_id' => $other->id, 'amount' => 999, 'remaining_amount' => 999]);
    }

    public function test_every_line_has_its_vault_effect_and_running_balance(): void
    {
        $lines = app(LedgerService::class)->page(['user_id' => $this->user->id], 50)->getCollection()->reverse()->values();

        $this->assertSame(
            ['payment', 'payment', 'withdrawal', 'withdrawal', 'reversal_refund', 'refund', 'payment', 'chargeback_opened', 'chargeback_lost', 'subscription'],
            $lines->pluck('kind')->all(),
        );
        $this->assertSame(
            [100.0, 100.0, 50.0, 50.0, 70.0, 30.0, 230.0, 230.0, 180.0, null],
            $lines->pluck('balance_after')->map(fn ($b) => $b === null ? null : (float) $b)->all(),
        );

        $totals = app(LedgerService::class)->totals(['user_id' => $this->user->id]);
        $this->assertSame(300.0, $totals['deposits']);
        $this->assertSame(50.0, $totals['withdrawn']);
        $this->assertSame(20.0, $totals['pulled_back']);
        $this->assertSame(40.0, $totals['refunded']);
        $this->assertSame(50.0, $totals['chargebacks_lost']);
        $this->assertSame(180.0, $totals['vault'], 'equals the two vaults: $30 + $150');
        $this->assertSame(4.99, $totals['subscriptions']);
    }

    public function test_the_page_shows_the_ledger_and_says_everything_adds_up(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.ledger.index', ['user' => 'Jacob.Turner@example.com']));

        $response->assertOk()
            ->assertSee('Jacob Turner')
            ->assertSee('Chargeback lost')
            ->assertSee('Pulled back')
            ->assertSee('$180.00')
            ->assertDontSee('Laura Bennett')
            ->assertDontSee('do not add up');

        $this->assertSame([], app(LedgerService::class)->mismatches());
    }

    public function test_a_vault_that_does_not_match_its_lines_is_flagged(): void
    {
        $this->holdB->update(['remaining_amount' => 200]); // the chargeback was never taken out

        $mismatches = app(LedgerService::class)->mismatches();
        $this->assertCount(1, $mismatches);
        $this->assertSame($this->holdB->id, $mismatches[0]['hold_id']);
        $this->assertSame(150.0, $mismatches[0]['ledger']);

        $this->actingAs($this->admin)->get(route('admin.ledger.index'))->assertOk()->assertSee('1 deposit(s) do not add up');
    }

    public function test_filters_by_deposit_and_type_and_rejects_bad_input(): void
    {
        $byHold = app(LedgerService::class)->page(['hold_id' => $this->holdB->id])->getCollection();
        $this->assertSame(['chargeback_lost', 'chargeback_opened', 'payment'], $byHold->pluck('kind')->all());

        $this->actingAs($this->admin)->get(route('admin.ledger.index', ['type' => 'refunds', 'user' => $this->user->id]))
            ->assertOk()->assertSee('admin panel')->assertDontSee('Chargeback lost');

        // An unknown user shows nothing (not everyone); junk values are ignored, not run.
        $this->actingAs($this->admin)->get(route('admin.ledger.index', ['user' => 'nobody@example.com']))
            ->assertOk()->assertSee('No user with ID or email')->assertSee('No transactions found');
        $this->actingAs($this->admin)->get(route('admin.ledger.index', ['type' => "x' OR 1=1", 'from' => 'nope', 'hold' => 'abc', 'user' => ['x']]))
            ->assertOk();
    }

    public function test_only_admins_can_open_the_ledger(): void
    {
        $this->actingAs($this->user)->get(route('admin.ledger.index'))->assertRedirect();
        $this->get(route('admin.ledger.index'))->assertRedirect();
    }
}
