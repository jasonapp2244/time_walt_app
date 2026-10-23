<?php

namespace Tests\Feature\Stripe;

use App\Jobs\SendPaymentSuccessNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * POST /api/stripe/create-payment-intent and /api/stripe/confirm-payment —
 * the deposit ("lock") path the Flutter Payment Sheet drives.
 */
class PaymentSheetTest extends TestCase
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

    private function lockRequest(array $overrides = []): array
    {
        return array_merge([
            'amount' => 25.50,
            'currency' => 'usd',
            'title' => 'Holiday fund',
            'hold_period_type' => 'custom',
            'hold_start_at' => now()->toDateString(),
            'hold_end_at' => now()->addDays(7)->toDateString(),
            'hold_hours' => 2,
            'hold_minutes' => 30,
        ], $overrides);
    }

    private function createIntent(array $overrides = [])
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stripe/create-payment-intent', $this->lockRequest($overrides));
    }

    private function stripeAcceptsIntent(bool $newCustomer = true): void
    {
        if ($newCustomer) {
            $this->stripe->respond('POST /v1/customers', ['id' => 'cus_new', 'object' => 'customer']);
        }
        $this->stripe->respond('POST /v1/ephemeral_keys', ['id' => 'ephkey_1', 'object' => 'ephemeral_key', 'secret' => 'ek_test_secret']);
        $this->stripe->respond('POST /v1/payment_intents', ['id' => 'pi_lock', 'object' => 'payment_intent', 'client_secret' => 'pi_lock_secret_abc']);
    }

    private function succeededIntent(string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 2550,
            'currency' => 'usd',
            'latest_charge' => [
                'id' => 'ch_lock',
                'object' => 'charge',
                'payment_method_details' => ['card' => [
                    'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030,
                    'funding' => 'credit', 'country' => 'US', 'wallet' => null,
                ]],
            ],
            'metadata' => [
                'user_id' => (string) $this->user->id,
                'hold_period_type' => 'custom',
                'hold_start_at' => now()->toIso8601String(),
                'hold_end_at' => now()->addDays(7)->toIso8601String(),
                'hold_days' => '7',
                'hold_hours' => '0',
                'hold_minutes' => '0',
                'title' => 'Holiday fund',
            ],
        ], $overrides);
    }

    private function confirm(string $paymentIntentId)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/stripe/confirm-payment', ['payment_intent_id' => $paymentIntentId]);
    }

    // ── create-payment-intent ────────────────────────────────────────────

    public function test_it_returns_everything_the_payment_sheet_needs(): void
    {
        $this->stripeAcceptsIntent();

        $this->createIntent()
            ->assertOk()
            ->assertJsonPath('data.payment_intent_id', 'pi_lock')
            ->assertJsonPath('data.client_secret', 'pi_lock_secret_abc')
            ->assertJsonPath('data.customer_id', 'cus_new')
            ->assertJsonPath('data.ephemeral_key', 'ek_test_secret')
            ->assertJsonPath('data.publishable_key', 'pk_test_fake')
            ->assertJsonPath('data.currency', 'usd');

        $sent = $this->stripe->requestsTo('POST', '/v1/payment_intents')[0]['params'];
        $this->assertSame(2550, $sent['amount']);
        $this->assertSame('usd', $sent['currency']);
        $this->assertSame('cus_new', $sent['customer']);
        $this->assertSame((string) $this->user->id, $sent['metadata']['user_id']);
        $this->assertSame('custom', $sent['metadata']['hold_period_type']);
        $this->assertSame('Holiday fund', $sent['metadata']['title']);
        $this->assertSame('2', $sent['metadata']['hold_hours']);
        $this->assertSame('30', $sent['metadata']['hold_minutes']);
    }

    public function test_it_rounds_the_amount_to_whole_cents(): void
    {
        $this->stripeAcceptsIntent();

        $this->createIntent(['amount' => 10.005])->assertOk();

        $this->assertSame(1001, $this->stripe->requestsTo('POST', '/v1/payment_intents')[0]['params']['amount']);
    }

    public function test_the_stripe_customer_is_created_once_and_reused(): void
    {
        $this->stripeAcceptsIntent();
        $this->createIntent()->assertOk();

        // On the free allowance the user's open deposits are checked first.
        $this->stripe->respond('GET /v1/payment_intents', ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/payment_intents']);
        $this->stripeAcceptsIntent(newCustomer: false);
        $this->createIntent()->assertOk()->assertJsonPath('data.customer_id', 'cus_new');

        $this->assertCount(1, $this->stripe->requestsTo('POST', '/v1/customers'));
        $this->assertSame(1, StripeCustomer::where('user_id', $this->user->id)->count());
    }

    public function test_invalid_input_is_rejected_before_stripe_is_called(): void
    {
        $this->createIntent(['amount' => 0.5])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->createIntent(['currency' => 'JPY'])->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->createIntent(['hold_start_at' => now()->subDays(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('hold_start_at');
        $this->createIntent(['hold_end_at' => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('hold_end_at');
        $this->createIntent(['hold_hours' => 24])->assertUnprocessable()->assertJsonValidationErrors('hold_hours');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/stripe/create-payment-intent', $this->lockRequest())->assertUnauthorized();
        $this->postJson('/api/stripe/confirm-payment', ['payment_intent_id' => 'pi_x'])->assertUnauthorized();

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_stripe_error_returns_a_generic_500(): void
    {
        $this->stripe->respond('POST /v1/customers', [
            'error' => ['type' => 'api_error', 'message' => 'Internal secret detail'],
        ], 500);

        $this->createIntent()
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Failed to create payment intent.');
    }

    // ── confirm-payment ──────────────────────────────────────────────────

    public function test_confirming_records_the_payment_and_the_titled_hold(): void
    {
        $this->stripe->respond('GET /v1/payment_intents/pi_lock', $this->succeededIntent('pi_lock'));

        $this->confirm('pi_lock')
            ->assertOk()
            ->assertJsonPath('message', 'Payment confirmed and records created successfully.')
            ->assertJsonPath('data.payment.amount', 25.5)
            ->assertJsonPath('data.payment.card_brand', 'visa')
            ->assertJsonPath('data.payment.card_last4', '4242')
            ->assertJsonPath('data.hold.title', 'Holiday fund')
            ->assertJsonPath('data.hold.status', 'holding');

        $payment = Payment::sole();
        $this->assertSame('succeeded', $payment->status);
        $this->assertSame('pi_lock', $payment->payment_intent_id);
        $this->assertSame($payment->id, PaymentHold::sole()->payment_id);

        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_confirming_twice_records_and_notifies_once(): void
    {
        $this->stripe->respond('GET /v1/payment_intents/pi_lock', $this->succeededIntent('pi_lock'));
        $this->stripe->respond('GET /v1/payment_intents/pi_lock', $this->succeededIntent('pi_lock'));

        $this->confirm('pi_lock')->assertOk();
        $this->confirm('pi_lock')
            ->assertOk()
            ->assertJsonPath('message', 'Payment already recorded.')
            ->assertJsonPath('data.hold.title', 'Holiday fund');

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, PaymentHold::count());
        Queue::assertPushed(SendPaymentSuccessNotification::class, 1);
    }

    public function test_an_unfinished_payment_is_not_recorded(): void
    {
        $this->stripe->respond('GET /v1/payment_intents/pi_lock', $this->succeededIntent('pi_lock', ['status' => 'processing']));

        $this->confirm('pi_lock')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Payment not completed yet. Status: processing');

        $this->assertSame(0, Payment::count());
        Queue::assertNothingPushed();
    }

    public function test_another_users_payment_cannot_be_claimed(): void
    {
        $other = User::factory()->create();
        $this->stripe->respond('GET /v1/payment_intents/pi_theirs', $this->succeededIntent('pi_theirs', [
            'metadata' => ['user_id' => (string) $other->id],
        ]));

        $this->confirm('pi_theirs')->assertForbidden();

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, PaymentHold::count());
    }

    public function test_a_malformed_payment_intent_id_is_rejected_without_calling_stripe(): void
    {
        $this->confirm('ch_not_an_intent')->assertUnprocessable()->assertJsonValidationErrors('payment_intent_id');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_the_receipt_respects_the_users_notification_settings(): void
    {
        UserNotificationSetting::create([
            'user_id' => $this->user->id,
            'transaction_alert' => false,
            'email_alert' => true,
        ]);
        $this->stripe->respond('GET /v1/payment_intents/pi_lock', $this->succeededIntent('pi_lock'));

        $this->confirm('pi_lock')->assertOk();

        $this->assertSame(1, Payment::count());
        Queue::assertNotPushed(SendPaymentSuccessNotification::class);
    }
}
