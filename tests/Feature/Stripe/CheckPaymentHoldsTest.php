<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendHoldPeriodEndedNotification;
use App\Models\PaymentHold;
use App\Models\UserNotificationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * check:payment-holds releases holds whose period has ended. Auto-transfer is
 * off here, so no case reaches Stripe.
 */
class CheckPaymentHoldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.auto_transfer_enabled' => false]);
        Queue::fake();
    }

    public function test_an_ended_hold_becomes_ready_and_the_user_is_told(): void
    {
        $hold = PaymentHold::factory()->create(['hold_end_at' => now()->subMinute()]);

        $this->artisan('check:payment-holds')->assertSuccessful();

        $hold->refresh();
        $this->assertSame('ready_for_transfer', $hold->status);
        $this->assertNotNull($hold->ready_at);
        Queue::assertPushed(SendHoldPeriodEndedNotification::class);
    }

    public function test_a_hold_still_in_its_period_is_left_alone(): void
    {
        $hold = PaymentHold::factory()->create(['hold_end_at' => now()->addMinute()]);

        $this->artisan('check:payment-holds')->assertSuccessful();

        $this->assertSame('holding', $hold->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_no_email_when_the_user_turned_transaction_alerts_off(): void
    {
        $hold = PaymentHold::factory()->create(['hold_end_at' => now()->subMinute()]);
        UserNotificationSetting::create([
            'user_id' => $hold->user_id,
            'transaction_alert' => false,
            'email_alert' => true,
        ]);

        $this->artisan('check:payment-holds')->assertSuccessful();

        $this->assertSame('ready_for_transfer', $hold->refresh()->status);
        Queue::assertNothingPushed();
    }
}
