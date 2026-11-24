<?php

namespace Tests\Feature\Stripe;

use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * stripe:fix-hold-metadata copies each hold's real length onto its old
 * PaymentIntent (which said hold_days = 30). Dry run by default.
 */
class FixStripeHoldMetadataTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private PaymentHold $hold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();

        $user = User::factory()->create(['full_name' => 'Daniel Brooks', 'email' => 'daniel.brooks@example.com']);
        $payment = Payment::factory()->create(['user_id' => $user->id, 'payment_intent_id' => 'pi_old_label']);

        $this->hold = PaymentHold::factory()->create([
            'payment_id' => $payment->id, 'user_id' => $user->id,
            'hold_start_at' => '2026-11-01 10:00:00', 'hold_end_at' => '2026-11-03 10:00:00',
            'hold_days' => 2, 'hold_hours' => 0, 'hold_minutes' => 0,
        ]);
    }

    private function stripeHasOldLabel(): void
    {
        $this->stripe->respond('GET /v1/payment_intents/pi_old_label', [
            'id' => 'pi_old_label', 'object' => 'payment_intent',
            'metadata' => ['user_id' => (string) $this->hold->user_id, 'hold_days' => '30', 'hold_hours' => '0', 'hold_minutes' => '0'],
        ]);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $this->stripeHasOldLabel();

        $this->artisan('stripe:fix-hold-metadata')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('hold_days 30 → 2')
            ->assertSuccessful();

        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/payment_intents'));
    }

    public function test_apply_writes_only_the_hold_labels(): void
    {
        $this->stripeHasOldLabel();
        $this->stripe->respond('POST /v1/payment_intents/pi_old_label', ['id' => 'pi_old_label', 'object' => 'payment_intent']);

        $this->artisan('stripe:fix-hold-metadata', ['--apply' => true])->assertSuccessful();

        $posts = $this->stripe->requestsTo('POST', '/v1/payment_intents');
        $this->assertCount(1, $posts);
        $this->assertSame(['metadata'], array_keys($posts[0]['params']), 'only metadata is sent: no amount or status change');
        $this->assertSame([
            'hold_days' => '2', 'hold_hours' => '0', 'hold_minutes' => '0', 'hold_until' => '2026-11-03T10:00:00+00:00',
        ], $posts[0]['params']['metadata']);
    }

    public function test_correct_or_foreign_payment_intents_are_left_alone(): void
    {
        $this->stripe->respond('GET /v1/payment_intents/pi_old_label', [
            'id' => 'pi_old_label', 'object' => 'payment_intent',
            'metadata' => ['user_id' => '999999', 'hold_days' => '30'],
        ]);

        $this->artisan('stripe:fix-hold-metadata', ['--apply' => true])->assertSuccessful();

        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/payment_intents'));
    }
}
