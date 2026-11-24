<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard "Total Transferred" counts completed transfers net of any part
 * pulled back from Connect for a refund.
 */
class DashboardTotalAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_transferred_is_net_of_refund_reversals(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'full_name' => 'Megan Carter', 'email' => 'megan.carter@example.com']);
        $user = User::factory()->create(['full_name' => 'John Miller', 'email' => 'john.miller@example.com']);

        $payment = Payment::factory()->create(['user_id' => $user->id, 'amount' => 90]);
        $hold = PaymentHold::factory()->create([
            'payment_id' => $payment->id, 'user_id' => $user->id, 'amount' => 90,
            'remaining_amount' => 0, 'status' => 'transferred',
        ]);

        Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $user->id, 'amount' => 50, 'status' => 'completed']);
        Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $user->id, 'amount' => 40, 'reversed_amount' => 15, 'status' => 'completed']);
        Transfer::factory()->create(['hold_id' => $hold->id, 'user_id' => $user->id, 'amount' => 9, 'status' => 'failed']);

        $this->actingAs($admin)->getJson(route('admin.dashboard.stats'))
            ->assertOk()
            ->assertJsonPath('total_transferred_amount', 75)
            ->assertJsonMissingPath('grand_total_amount');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Hold + Transfer');
    }
}
