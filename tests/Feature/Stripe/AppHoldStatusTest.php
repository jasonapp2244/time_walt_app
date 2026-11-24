<?php

namespace Tests\Feature\Stripe;

use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile app never receives the admin-only hold status `refunded`: a
 * fully refunded deposit reaches it as `canceled`, a status every released
 * app version already handles. The admin panel keeps the real status.
 */
class AppHoldStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PaymentHold $refunded;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['full_name' => 'Kevin Hughes', 'email' => 'kevin.hughes@example.com']);

        $payment = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 40, 'refunded_amount' => 40]);
        $this->refunded = PaymentHold::factory()->create([
            'payment_id' => $payment->id, 'user_id' => $this->user->id, 'amount' => 40,
            'remaining_amount' => 0, 'status' => 'refunded',
            'hold_start_at' => now()->subDays(5), 'hold_end_at' => now()->subDays(2),
        ]);
        Refund::factory()->create(['payment_id' => $payment->id, 'payment_hold_id' => $this->refunded->id, 'user_id' => $this->user->id, 'amount' => 40, 'status' => 'succeeded']);

        // A normal deposit with a withdrawal, so every list has rows.
        $other = Payment::factory()->create(['user_id' => $this->user->id, 'amount' => 25]);
        $normal = PaymentHold::factory()->create([
            'payment_id' => $other->id, 'user_id' => $this->user->id, 'amount' => 25,
            'remaining_amount' => 15, 'status' => 'partial_transferred',
            'hold_start_at' => now()->subDays(5), 'hold_end_at' => now()->subDays(2),
        ]);
        Transfer::factory()->create(['hold_id' => $normal->id, 'user_id' => $this->user->id, 'amount' => 10, 'status' => 'completed']);
    }

    /** Every value of a `status` or `hold_status` key anywhere in the JSON. */
    private function statuses(array $json): array
    {
        $found = [];

        array_walk_recursive($json, function ($value, $key) use (&$found) {
            if (in_array($key, ['status', 'hold_status'], true)) {
                $found[] = $value;
            }
        });

        return $found;
    }

    public function test_app_endpoints_never_send_the_refunded_status(): void
    {
        $this->assertSame('canceled', $this->refunded->appStatus());

        $endpoints = [
            '/api/payment-holds',
            '/api/transactions/all',
            '/api/transactions/checkouts',
            '/api/transactions/withdraws',
            '/api/transactions/hold-amounts',
            '/api/transactions/ready-for-transfer',
        ];

        $sawCanceled = false;

        foreach ($endpoints as $url) {
            $json = $this->actingAs($this->user, 'sanctum')->getJson($url)->assertOk()->json();
            $statuses = $this->statuses($json);

            $this->assertNotContains('refunded', $statuses, "{$url} sent the admin-only status `refunded` to the app");
            $sawCanceled = $sawCanceled || in_array('canceled', $statuses, true);
        }

        $this->assertTrue($sawCanceled, 'the refunded deposit reaches the app as canceled');
    }

    public function test_the_admin_panel_keeps_the_real_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'megan.carter@example.com']);

        $this->assertSame('refunded', $this->refunded->refresh()->status);

        $this->actingAs($admin)->get(route('admin.payment-holds.index'))
            ->assertOk()
            ->assertSee('badge-refunded', false);

        $this->actingAs($admin)->get(route('admin.users.show', $this->user))
            ->assertOk()
            ->assertViewHas('activity', fn ($a) => $a['holds']['statuses']['refunded']['count'] === 1);
    }
}
