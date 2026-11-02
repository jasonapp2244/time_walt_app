<?php

namespace Tests\Feature\Stripe;

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
 * The webhook endpoint itself: method, secret, signature, duplicate
 * protection, failure/retry bookkeeping and checkout.session.completed.
 */
class WebhookEndpointTest extends TestCase
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

    private function intent(string $id): array
    {
        return [
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 2500,
            'currency' => 'usd',
            'latest_charge' => 'ch_ep',
            'metadata' => [
                'user_id' => (string) $this->user->id,
                'hold_period_type' => 'custom',
                'hold_start_at' => now()->toIso8601String(),
                'hold_end_at' => now()->addDays(3)->toIso8601String(),
                'hold_days' => '3',
                'hold_hours' => '0',
                'hold_minutes' => '0',
                'title' => 'Trip',
            ],
        ];
    }

    private function stripeServesDeposit(string $id): void
    {
        $this->stripe
            ->respond("GET /v1/payment_intents/{$id}", $this->intent($id))
            ->respond('GET /v1/charges/ch_ep', [
                'id' => 'ch_ep',
                'object' => 'charge',
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
            ]);
    }

    private function checkoutSession(string $mode, ?string $paymentIntent): array
    {
        return [
            'id' => 'cs_test_'.uniqid(),
            'object' => 'checkout.session',
            'mode' => $mode,
            'payment_intent' => $paymentIntent,
            'amount_total' => 2500,
            'currency' => 'usd',
        ];
    }

    public function test_get_is_not_allowed(): void
    {
        $this->getJson('/api/stripe/webhook')->assertStatus(405);
    }

    public function test_a_missing_secret_is_a_server_error(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(500)
            ->assertJson(['error' => 'Webhook secret not configured']);
    }

    public function test_a_request_without_a_signature_is_rejected(): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->stripeEvent('invoice.paid', ['id' => 'in_x'])))
            ->assertStatus(400)
            ->assertJson(['error' => 'Invalid signature']);

        $this->assertSame(0, StripeWebhookEvent::count());
    }

    public function test_a_body_changed_after_signing_is_rejected(): void
    {
        $event = $this->stripeEvent('invoice.paid', ['id' => 'in_x']);
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_fake');

        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], str_replace('in_x', 'in_y', $payload))->assertStatus(400);

        $this->assertSame(0, StripeWebhookEvent::count());
    }

    public function test_a_valid_unhandled_event_is_acknowledged_and_recorded(): void
    {
        $event = $this->stripeEvent('customer.created', ['id' => 'cus_new', 'object' => 'customer']);

        $this->postStripeWebhook($event)->assertOk()->assertJson(['received' => true]);

        $row = StripeWebhookEvent::sole();
        $this->assertSame($event['id'], $row->stripe_event_id);
        $this->assertSame('processed', $row->status);
        $this->assertNotNull($row->processed_at);
    }

    public function test_an_event_already_processed_is_skipped(): void
    {
        $this->stripeServesDeposit('pi_dup');
        $event = $this->stripeEvent('payment_intent.succeeded', $this->intent('pi_dup'));

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentHold::count());
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
        $this->assertCount(1, $this->stripe->requestsTo('GET', '/v1/charges'), 'The duplicate must not reach the handler.');
    }

    public function test_an_event_being_processed_by_another_request_gets_409(): void
    {
        $event = $this->stripeEvent('payment_intent.succeeded', $this->intent('pi_busy'));
        StripeWebhookEvent::create([
            'stripe_event_id' => $event['id'],
            'event_type' => $event['type'],
            'status' => 'pending',
            'payload' => $event,
        ]);

        $this->postStripeWebhook($event)->assertStatus(409);

        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', StripeWebhookEvent::sole()->status);
    }

    public function test_an_abandoned_pending_event_can_be_claimed_again(): void
    {
        $this->stripeServesDeposit('pi_stale');
        $event = $this->stripeEvent('payment_intent.succeeded', $this->intent('pi_stale'));
        $row = StripeWebhookEvent::create([
            'stripe_event_id' => $event['id'],
            'event_type' => $event['type'],
            'status' => 'pending',
            'payload' => $event,
        ]);
        StripeWebhookEvent::whereKey($row->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame('processed', $row->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_failing_handler_is_recorded_and_the_retry_succeeds(): void
    {
        // Stripe is down for the charge lookup on the first delivery.
        $this->stripe->respond('GET /v1/charges/ch_ep', ['error' => ['message' => 'Stripe is down', 'type' => 'api_error']], 500);
        $event = $this->stripeEvent('payment_intent.succeeded', $this->intent('pi_retry'));

        $this->postStripeWebhook($event)->assertStatus(500)->assertJson(['error' => 'Webhook processing failed']);

        $row = StripeWebhookEvent::sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, $row->retry_count);
        $this->assertNotNull($row->error_message);
        $this->assertSame(0, Payment::count());

        $this->stripeServesDeposit('pi_retry');

        $this->postStripeWebhook($event)->assertOk();

        $row->refresh();
        $this->assertSame('processed', $row->status);
        $this->assertNull($row->error_message);
        $this->assertSame(1, Payment::count());
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_a_paid_checkout_session_records_the_deposit_and_sends_one_receipt(): void
    {
        $this->stripeServesDeposit('pi_cs');

        $this->postStripeWebhook($this->stripeEvent('checkout.session.completed', $this->checkoutSession('payment', 'pi_cs')))
            ->assertOk();

        $payment = Payment::sole();
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame($this->user->id, $payment->user_id);
        $this->assertSame('Trip', PaymentHold::sole()->title);
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_a_checkout_session_after_payment_intent_succeeded_sends_no_second_receipt(): void
    {
        $this->stripeServesDeposit('pi_both');
        $this->postStripeWebhook($this->stripeEvent('payment_intent.succeeded', $this->intent('pi_both')))->assertOk();

        $this->stripeServesDeposit('pi_both');
        $this->postStripeWebhook($this->stripeEvent('checkout.session.completed', $this->checkoutSession('payment', 'pi_both')))
            ->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentHold::count());
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_a_subscription_checkout_session_is_ignored(): void
    {
        $this->postStripeWebhook($this->stripeEvent('checkout.session.completed', $this->checkoutSession('subscription', null)))
            ->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertSame([], $this->stripe->requests);
        $this->assertSame('processed', StripeWebhookEvent::sole()->status);
    }
}
