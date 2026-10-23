<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendPaymentFailedNotification;
use App\Jobs\SendPaymentSuccessNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * Deposit webhooks (payment_intent.*) through the real, signature-checked
 * endpoint, including how they interleave with the app's /confirm-payment.
 */
class DepositWebhookTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();

        $this->user = User::factory()->create(['timezone' => 'UTC']);
    }

    private function metadata(): array
    {
        return [
            'user_id' => (string) $this->user->id,
            'hold_period_type' => 'custom',
            'hold_start_at' => now()->toIso8601String(),
            'hold_end_at' => now()->addDays(7)->toIso8601String(),
            'hold_days' => '7',
            'hold_hours' => '0',
            'hold_minutes' => '0',
            'title' => 'Rainy day',
        ];
    }

    /** The PaymentIntent as Stripe puts it in an event: latest_charge is an ID. */
    private function intent(string $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 4000,
            'currency' => 'usd',
            'latest_charge' => 'ch_dep',
            'metadata' => $this->metadata(),
        ], $overrides);
    }

    private function stripeServesCharge(): void
    {
        $this->stripe->respond('GET /v1/charges/ch_dep', [
            'id' => 'ch_dep',
            'object' => 'charge',
            'payment_method_details' => ['card' => [
                'brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 2031,
                'funding' => 'debit', 'country' => 'GB', 'wallet' => ['type' => 'google_pay'],
            ]],
        ]);
    }

    private function stripeServesIntent(string $id): void
    {
        $this->stripe->respond("GET /v1/payment_intents/{$id}", $this->intent($id));
    }

    private function confirm(string $id)
    {
        $this->stripe->respond("GET /v1/payment_intents/{$id}", $this->intent($id, [
            'latest_charge' => ['id' => 'ch_dep', 'object' => 'charge', 'payment_method_details' => ['card' => ['brand' => 'mastercard', 'last4' => '4444']]],
        ]));

        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stripe/confirm-payment', ['payment_intent_id' => $id]);
    }

    public function test_the_webhook_after_confirm_does_not_send_a_second_receipt(): void
    {
        $this->confirm('pi_dep')->assertOk();
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);

        $this->stripeServesCharge();
        $this->stripeServesIntent('pi_dep');
        $this->postStripeWebhook($this->stripeEvent('payment_intent.succeeded', $this->intent('pi_dep')))->assertOk();

        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentHold::count());
        $this->assertSame(1, $this->user->fresh()->free_locks_used);
    }

    public function test_a_webhook_that_arrives_first_records_the_deposit_and_keeps_its_title(): void
    {
        $this->stripeServesCharge();
        $this->stripeServesIntent('pi_dep');

        $this->postStripeWebhook($this->stripeEvent('payment_intent.succeeded', $this->intent('pi_dep')))->assertOk();

        $payment = Payment::sole();
        $this->assertSame('succeeded', $payment->status);
        $this->assertEquals(40, $payment->amount);
        $this->assertSame('mastercard', $payment->card_brand);
        $this->assertSame('google_pay', $payment->payment_method_type);

        $hold = PaymentHold::sole();
        $this->assertSame($payment->id, $hold->payment_id);
        $this->assertSame('Rainy day', $hold->title);
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);

        // The app's /confirm then finds the deposit already recorded.
        $this->confirm('pi_dep')->assertOk()->assertJsonPath('message', 'Payment already recorded.');
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentHold::count());
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_a_payment_that_failed_earlier_and_then_succeeds_is_receipted(): void
    {
        Payment::factory()->create([
            'user_id' => $this->user->id,
            'payment_intent_id' => 'pi_retry',
            'status' => 'failed',
        ]);
        $this->stripeServesCharge();
        $this->stripeServesIntent('pi_retry');

        $this->postStripeWebhook($this->stripeEvent('payment_intent.succeeded', $this->intent('pi_retry')))->assertOk();

        $this->assertSame('succeeded', Payment::sole()->status);
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_a_payment_intent_that_is_not_a_deposit_is_ignored(): void
    {
        $this->postStripeWebhook($this->stripeEvent('payment_intent.succeeded', $this->intent('pi_sub_invoice', ['metadata' => []])))
            ->assertOk();

        $this->assertSame(0, Payment::count());
        Queue::assertNothingPushed();
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_replayed_event_is_processed_once(): void
    {
        $this->stripeServesCharge();
        $this->stripeServesIntent('pi_dep');
        $event = $this->stripeEvent('payment_intent.succeeded', $this->intent('pi_dep'));

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame('processed', StripeWebhookEvent::sole()->status);
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_a_bad_signature_is_rejected_and_nothing_is_recorded(): void
    {
        $payload = json_encode($this->stripeEvent('payment_intent.succeeded', $this->intent('pi_forged')));

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload)->assertStatus(400)->assertJsonPath('error', 'Invalid signature');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, StripeWebhookEvent::count());
    }

    public function test_a_failed_payment_is_marked_and_the_user_told(): void
    {
        Payment::factory()->create([
            'user_id' => $this->user->id,
            'payment_intent_id' => 'pi_declined',
            'status' => 'pending',
        ]);

        $this->postStripeWebhook($this->stripeEvent('payment_intent.payment_failed', $this->intent('pi_declined', [
            'status' => 'requires_payment_method',
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ])))->assertOk();

        $payment = Payment::sole();
        $this->assertSame('failed', $payment->status);
        $this->assertSame('Your card was declined.', $payment->failure_reason);
        Queue::assertPushed(SendPaymentFailedNotification::class, 1);
    }

    public function test_a_canceled_payment_cancels_its_hold(): void
    {
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'payment_intent_id' => 'pi_cancel',
            'status' => 'pending',
        ]);
        $hold = PaymentHold::factory()->create(['payment_id' => $payment->id, 'user_id' => $this->user->id, 'status' => 'holding']);

        $this->postStripeWebhook($this->stripeEvent('payment_intent.canceled', $this->intent('pi_cancel', [
            'status' => 'canceled',
            'cancellation_reason' => 'abandoned',
        ])))->assertOk();

        $this->assertSame('canceled', $payment->fresh()->status);
        $this->assertSame('abandoned', $payment->fresh()->failure_reason);
        $this->assertSame('canceled', $hold->fresh()->status);
    }
}
