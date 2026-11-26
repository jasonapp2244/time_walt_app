<?php

namespace Tests\Feature\Admin;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeFee;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Stripe fee on each ledger line, per user and in total (Ledger → Fees).
 */
class LedgerFeesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private User $other;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Megan Carter', 'email' => 'megan.carter@example.com']);
        $this->user = User::factory()->create(['full_name' => 'Jacob Turner', 'email' => 'jacob.turner@example.com']);
        $this->other = User::factory()->create(['full_name' => 'Laura Bennett', 'email' => 'laura.bennett@example.com']);
        $day = Carbon::parse('2026-11-01 12:00:00', 'UTC');

        // $2,000 deposit, Stripe fee $58.30; a chargeback on it (won) with a $15 fee.
        $this->payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 2000, 'paid_at' => $day]);
        $hold = PaymentHold::factory()->create(['payment_id' => $this->payment->id, 'user_id' => $this->user->id, 'amount' => 2000, 'remaining_amount' => 2000, 'status' => 'holding']);
        $dispute = Dispute::unguarded(fn () => Dispute::create([
            'payment_id' => $this->payment->id, 'payment_hold_id' => $hold->id, 'user_id' => $this->user->id,
            'stripe_dispute_id' => 'dp_fee_1', 'amount' => 10, 'vault_amount' => 10, 'currency' => 'usd',
            'outcome' => 'won', 'stripe_status' => 'won', 'needs_attention' => false,
            'created_at' => $day->copy()->addDay(), 'closed_at' => $day->copy()->addDays(2),
        ]));
        // A failed payment: Stripe charges nothing.
        Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 25, 'status' => 'failed', 'paid_at' => null, 'created_at' => $day->copy()->addHour()]);

        $this->fee('txn_a', 'deposit', 58.30, $this->user->id, $day, ['payment_id' => $this->payment->id]);
        $this->fee('txn_b', 'chargeback', 15.00, $this->user->id, $day->copy()->addDay(), ['dispute_id' => $dispute->id, 'payment_id' => $this->payment->id]);
        $this->fee('txn_c', 'platform', 2.00, null, $day->copy()->addDays(3), ['description' => 'Connect (2026-11-01): Active account billing']);
        $this->fee('txn_d', 'deposit', 1.00, $this->other->id, $day->copy()->addDays(20));
    }

    private function fee(string $id, string $category, float $fee, ?int $userId, Carbon $at, array $extra = []): StripeFee
    {
        return StripeFee::create($extra + [
            'balance_transaction_id' => $id, 'type' => $category === 'platform' ? 'stripe_fee' : 'charge', 'category' => $category,
            'user_id' => $userId, 'gross' => 0, 'fee' => $fee, 'net' => -$fee, 'currency' => 'usd', 'occurred_at' => $at,
        ]);
    }

    public function test_each_line_shows_its_fee_once_and_the_vault_is_unchanged(): void
    {
        $lines = app(LedgerService::class)->page(['user_id' => $this->user->id], 50)->getCollection()->keyBy(fn ($l) => $l->kind.':'.$l->status);

        $this->assertSame(58.30, (float) $lines['payment:succeeded']->fee);
        $this->assertNull($lines['payment:failed']->fee);
        $this->assertSame(15.00, (float) $lines['chargeback_opened:won']->fee);
        $this->assertNull($lines['chargeback_won:won']->fee, 'the chargeback fee is shown once');
        $this->assertSame(2000.0, (float) $lines['payment:succeeded']->balance_after, 'fees never change the vault');

        $totals = app(LedgerService::class)->totals(['user_id' => $this->user->id]);
        $this->assertSame(73.30, $totals['fees']);
        $this->assertSame(58.30, $totals['deposit_fees']);
        $this->assertSame([], app(LedgerService::class)->mismatches());
    }

    public function test_fee_totals_cover_every_fee_including_platform_fees(): void
    {
        $all = app(LedgerService::class)->feeTotals([]);
        $this->assertSame(76.30, $all['total']);
        $this->assertSame(2.00, $all['platform']);
        $this->assertSame(74.30, $all['users']);
        $this->assertSame(4, $all['records']);
        $this->assertSame($this->user->id, $all['top_users'][0]['user_id']);
        $this->assertSame(73.30, $all['top_users'][0]['fees']);

        $this->assertSame(73.30, app(LedgerService::class)->feeTotals(['user_id' => $this->user->id])['total']);
        $this->assertSame(15.00, app(LedgerService::class)->feeTotals(['category' => 'chargeback'])['total']);
        $this->assertSame(1.00, app(LedgerService::class)->feeTotals(['from' => '2026-11-15', 'to' => '2026-11-30'])['total']);
    }

    public function test_a_fee_on_a_payment_with_no_user_is_kept_apart_from_platform_fees(): void
    {
        $this->fee('txn_e', 'subscription', 0.50, null, Carbon::parse('2026-11-05', 'UTC'), ['description' => 'Subscription creation']);

        $all = app(LedgerService::class)->feeTotals([]);
        $this->assertSame(76.80, $all['total']);
        $this->assertSame(2.00, $all['platform'], 'only Stripe account fees are platform fees');
        $this->assertSame(0.50, $all['unlinked']);
        $this->assertSame(74.30, $all['users']);

        $this->actingAs($this->admin)->get(route('admin.ledger.fees'))
            ->assertOk()->assertSee('Not linked to a user')->assertSee('No user found')->assertSee('Platform / Connect fees');
    }

    public function test_the_ledger_page_shows_fees_per_line_and_for_the_user(): void
    {
        $this->actingAs($this->admin)->get(route('admin.ledger.index', ['user' => $this->user->id]))
            ->assertOk()
            ->assertSee('Stripe fee')
            ->assertSee("This user's Stripe fees")
            ->assertSee('−$73.30', false)
            ->assertSee('−$58.30', false)
            ->assertSee('Deposits after fees')
            ->assertDontSee('on the lines below');
    }

    public function test_the_user_total_matches_the_fees_tab_even_for_fees_with_no_ledger_line(): void
    {
        // A fee on a Stripe charge for this user that the app has no payment for.
        $this->fee('txn_f', 'deposit', 1.20, $this->user->id, Carbon::parse('2026-11-06', 'UTC'));

        $this->actingAs($this->admin)->get(route('admin.ledger.index', ['user' => $this->user->id]))
            ->assertOk()
            ->assertSee('−$74.50', false)
            ->assertSee('−$73.30 on the lines below', false);
        $this->actingAs($this->admin)->get(route('admin.ledger.fees', ['user' => $this->user->id]))
            ->assertOk()->assertSee('$74.50');
    }

    public function test_the_fees_page_lists_every_fee_with_totals_and_filters(): void
    {
        $this->actingAs($this->admin)->get(route('admin.ledger.fees'))
            ->assertOk()
            ->assertSee('Total Stripe fees')
            ->assertSee('$76.30')
            ->assertSee('Platform (no user)')
            ->assertSee('Users with the most fees')
            ->assertSee('Jacob Turner')
            ->assertSee('Laura Bennett')
            ->assertSee('…'.substr('txn_a', -8), false)
            ->assertSee('The fee sync has not run yet');

        $this->actingAs($this->admin)->get(route('admin.ledger.fees', ['user' => 'laura.bennett@example.com']))
            ->assertOk()->assertSee('$1.00')->assertDontSee('Jacob Turner');

        $this->actingAs($this->admin)->get(route('admin.ledger.fees', ['user' => 'nobody@example.com']))
            ->assertOk()->assertSee('No user with ID or email')->assertSee('No fees found for these filters');

        $this->actingAs($this->admin)->get(route('admin.ledger.fees', ['category' => "x' OR 1=1", 'from' => 'nope', 'user' => ['x']]))
            ->assertOk()->assertSee('$76.30');
    }

    public function test_the_fees_download_and_the_user_page(): void
    {
        $this->actingAs($this->admin)->get(route('admin.ledger.fees.export', ['format' => 'xlsx']))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($this->admin)->get(route('admin.ledger.fees.export', ['format' => 'pdf', 'user' => $this->user->id]))
            ->assertOk();

        $this->actingAs($this->admin)->get(route('admin.users.show', $this->user))
            ->assertOk()->assertSee('Stripe fees: $73.30');
    }

    public function test_only_admins_can_see_fees(): void
    {
        $this->actingAs($this->user)->get(route('admin.ledger.fees'))->assertRedirect();
        $this->actingAs($this->user)->get(route('admin.ledger.fees.export'))->assertRedirect();
        $this->get(route('admin.ledger.fees'))->assertRedirect();
    }
}
