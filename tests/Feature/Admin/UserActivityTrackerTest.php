<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The activity tracker on the admin user page: counts by status for the
 * user's deposits, card payments, withdrawals and refunds, and the Stripe
 * account state. Other users' records never leak in.
 */
class UserActivityTrackerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Megan Carter', 'email' => 'megan.carter@example.com']);
        $this->user = User::factory()->create(['full_name' => 'John Miller', 'email' => 'john.miller@example.com']);
    }

    private function hold(User $user, float $amount, string $status, string $paymentStatus = 'succeeded'): PaymentHold
    {
        $payment = Payment::factory()->create(['user_id' => $user->id, 'amount' => $amount, 'status' => $paymentStatus]);

        return PaymentHold::factory()->create([
            'payment_id' => $payment->id, 'user_id' => $user->id, 'amount' => $amount,
            'remaining_amount' => $amount, 'status' => $status,
        ]);
    }

    private function seedActivity(): void
    {
        $locked = $this->hold($this->user, 100, 'holding');
        $withdrawn = $this->hold($this->user, 50, 'transferred');
        $this->hold($this->user, 20, 'refunded');
        Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 30, 'status' => 'failed']);

        Transfer::factory()->create(['hold_id' => $withdrawn->id, 'user_id' => $this->user->id, 'amount' => 50, 'reversed_amount' => 10, 'status' => 'completed']);
        Transfer::factory()->create(['hold_id' => $locked->id, 'user_id' => $this->user->id, 'amount' => 5, 'status' => 'failed']);

        Refund::factory()->create(['user_id' => $this->user->id, 'payment_hold_id' => $locked->id, 'payment_id' => $locked->payment_id, 'amount' => 20, 'status' => 'succeeded']);
        Refund::factory()->create(['user_id' => $this->user->id, 'payment_hold_id' => $locked->id, 'payment_id' => $locked->payment_id, 'amount' => 7, 'status' => 'pending', 'needs_attention' => true]);

        StripeConnectAccount::create(['user_id' => $this->user->id, 'connect_account_id' => 'acct_tracker_1', 'status' => 'restricted', 'payouts_enabled' => false]);

        // Another user's records must not be counted.
        $other = User::factory()->create(['full_name' => 'Laura Bennett', 'email' => 'laura.bennett@example.com']);
        $this->hold($other, 999, 'holding');
    }

    public function test_tracker_counts_each_record_type_by_status(): void
    {
        $this->seedActivity();

        $response = $this->actingAs($this->admin)->get(route('admin.users.show', $this->user));
        $response->assertOk();

        $activity = $response->viewData('activity');
        $this->assertSame(3, $activity['holds']['total']);
        $this->assertSame(1, $activity['holds']['statuses']['holding']['count']);
        $this->assertSame(100.0, $activity['holds']['statuses']['holding']['amount']);
        $this->assertSame(1, $activity['holds']['statuses']['refunded']['count']);

        $this->assertSame(4, $activity['payments']['total']);
        $this->assertSame(1, $activity['payments']['statuses']['failed']['count']);

        $this->assertSame(2, $activity['transfers']['total']);
        $this->assertSame(1, $activity['transfers']['statuses']['failed']['count']);
        $this->assertSame(['count' => 1, 'amount' => 10.0], $activity['transfers']['reversed']);

        $this->assertSame(2, $activity['refunds']['total']);
        $this->assertSame(20.0, $activity['refunds']['statuses']['succeeded']['amount']);
        $this->assertSame(1, $activity['refunds']['attention']);

        $this->assertSame('restricted', $activity['stripe']['status']);
        $this->assertFalse($activity['stripe']['payouts_enabled']);

        // Total withdrawn is net of the $10 pulled back for a refund.
        $amounts = $response->viewData('userAmounts');
        $this->assertSame(40.0, $amounts['total_withdrawn']);

        // Total amount = held $100 (locked) + ready $0 + withdrawn $40.
        $this->assertSame(140.0, $amounts['total_amount']);
        $this->assertSame(3, $amounts['holds_count']);
        $this->assertSame(2, $amounts['transfers_count']);
        $response->assertSee('$140.00', false)->assertSee('(hold + transfer)', false);

        $response->assertSee('Activity tracker')
            ->assertSee('Restricted (blocked by Stripe)')
            ->assertSee('1 failed card payment(s)')
            ->assertSee('1 refund(s) need attention');
    }

    public function test_a_status_count_filters_the_table_and_bad_values_are_ignored(): void
    {
        $this->seedActivity();

        $filtered = $this->actingAs($this->admin)->get(route('admin.users.show', ['user' => $this->user->id, 'hold_status' => 'holding', 'transfer_status' => 'failed']));
        $filtered->assertOk();
        $this->assertSame(1, $filtered->viewData('paymentHolds')->total());
        $this->assertSame('holding', $filtered->viewData('paymentHolds')->first()->status);
        $this->assertSame(1, $filtered->viewData('transfers')->total());
        $filtered->assertSee('Show all');

        $ignored = $this->actingAs($this->admin)->get(route('admin.users.show', ['user' => $this->user->id, 'hold_status' => "x' OR 1=1", 'transfer_status' => 'nope']));
        $ignored->assertOk();
        $this->assertSame(3, $ignored->viewData('paymentHolds')->total());
        $this->assertSame(2, $ignored->viewData('transfers')->total());
    }

    public function test_user_without_activity_shows_zeros_and_no_stripe_account(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.users.show', $this->user));

        $response->assertOk()->assertSee('Not connected');
        $this->assertSame(0, $response->viewData('activity')['holds']['total']);
        $this->assertFalse($response->viewData('activity')['stripe']['connected']);
    }

    public function test_user_stats_endpoint_reports_withdrawn_net_of_reversals(): void
    {
        $this->seedActivity();

        $this->actingAs($this->admin)->getJson(route('admin.users.stats.show', $this->user))
            ->assertOk()
            ->assertJsonPath('total_withdrawn', 40)
            ->assertJsonPath('total_amount', 140);
    }
}
