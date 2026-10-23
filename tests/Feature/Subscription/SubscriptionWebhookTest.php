<?php

namespace Tests\Feature\Subscription;

use App\Jobs\SendSubscriptionPaymentIssueNotification;
use App\Models\Payment;
use App\Models\StripeCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * Subscription webhooks through the real, signature-checked endpoint.
 * Payload shapes follow Stripe API 2025-12-15.clover.
 */
class SubscriptionWebhookTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_premium']);
    }

    private function localSubscription(string $stripeId, string $status = 'active'): Subscription
    {
        return Subscription::factory()->create([
            'user_id' => $this->user->id,
            'subscription_plan_id' => $this->plan->id,
            'stripe_subscription_id' => $stripeId,
            'status' => $status,
        ]);
    }

    /**
     * Post a subscription event. The handler re-reads the subscription from
     * Stripe, so Stripe answers with $current (by default the event's copy).
     */
    private function postSubscriptionEvent(string $type, array $object, ?array $current = null)
    {
        $this->stripe->respond('GET /v1/subscriptions/'.$object['id'], $current ?? $object);

        return $this->postStripeWebhook($this->stripeEvent($type, $object));
    }

    private function invoice(string $subscriptionId, string $billingReason): array
    {
        return [
            'id' => 'in_'.uniqid(),
            'object' => 'invoice',
            'billing_reason' => $billingReason,
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/test',
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => ['subscription' => $subscriptionId],
            ],
        ];
    }

    public function test_subscription_created_elsewhere_is_mirrored_from_its_metadata(): void
    {
        $this->postSubscriptionEvent('customer.subscription.created', $this->stripeSubscription('sub_hook', 'active', [
            'metadata' => ['user_id' => (string) $this->user->id, 'subscription_plan_id' => (string) $this->plan->id],
        ]))->assertOk();

        $subscription = Subscription::sole();
        $this->assertSame($this->user->id, $subscription->user_id);
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($this->user->isSubscribed());
    }

    public function test_without_metadata_the_user_and_plan_are_found_by_customer_and_price(): void
    {
        StripeCustomer::create(['user_id' => $this->user->id, 'stripe_customer_id' => 'cus_known']);

        $this->postSubscriptionEvent('customer.subscription.updated', $this->stripeSubscription('sub_bare', 'active', [
            'customer' => 'cus_known',
            'items' => ['data' => [['price' => ['id' => 'price_premium']]]],
        ]))->assertOk();

        $this->assertSame($this->plan->id, Subscription::sole()->subscription_plan_id);
    }

    public function test_deleted_ends_access(): void
    {
        $this->localSubscription('sub_end');

        $this->postSubscriptionEvent('customer.subscription.deleted', $this->stripeSubscription('sub_end', 'canceled', [
            'canceled_at' => now()->timestamp,
        ]))->assertOk();

        $this->assertSame('canceled', Subscription::sole()->status);
        $this->assertFalse($this->user->isSubscribed());
    }

    public function test_a_late_active_event_cannot_undo_a_cancellation(): void
    {
        $this->localSubscription('sub_late', 'canceled');

        // A delayed retry of an older "updated: active" event, while Stripe
        // already has the subscription canceled.
        $this->postSubscriptionEvent(
            'customer.subscription.updated',
            $this->stripeSubscription('sub_late', 'active'),
            $this->stripeSubscription('sub_late', 'canceled', ['canceled_at' => now()->timestamp])
        )->assertOk();

        $this->assertSame('canceled', Subscription::sole()->status);
        $this->assertFalse($this->user->isSubscribed());
    }

    public function test_switching_between_monthly_and_yearly_moves_the_plan(): void
    {
        $yearly = SubscriptionPlan::factory()->create(['interval' => 'year', 'price' => 49.99, 'stripe_price_id' => 'price_yearly']);
        $this->localSubscription('sub_switch');

        $this->postSubscriptionEvent('customer.subscription.updated', $this->stripeSubscription('sub_switch', 'active', [
            'items' => ['data' => [['price' => ['id' => 'price_yearly']]]],
        ]))->assertOk();

        $this->assertSame($yearly->id, Subscription::sole()->subscription_plan_id);
    }

    public function test_a_renewal_extends_the_paid_period(): void
    {
        $this->localSubscription('sub_renew')->update(['current_period_end' => now()->addDay()]);
        $nextEnd = now()->addMonths(2)->startOfSecond();
        $this->stripe->respond('GET /v1/subscriptions/sub_renew', $this->stripeSubscription('sub_renew', 'active', [
            'items' => ['data' => [['current_period_end' => $nextEnd->timestamp]]],
        ]));

        $this->postStripeWebhook($this->stripeEvent('invoice.paid', $this->invoice('sub_renew', 'subscription_cycle')))->assertOk();

        $this->assertTrue(Subscription::sole()->current_period_end->equalTo($nextEnd));
        Queue::assertNothingPushed();
    }

    public function test_a_failed_renewal_keeps_access_and_emails_the_user(): void
    {
        $this->localSubscription('sub_late');
        $this->stripe->respond('GET /v1/subscriptions/sub_late', $this->stripeSubscription('sub_late', 'past_due'));

        $this->postStripeWebhook($this->stripeEvent('invoice.payment_failed', $this->invoice('sub_late', 'subscription_cycle')))->assertOk();

        $this->assertSame('past_due', Subscription::sole()->status);
        $this->assertTrue($this->user->isSubscribed());
        Queue::assertPushed(SendSubscriptionPaymentIssueNotification::class, fn ($job) => $job->kind === 'failed'
            && $job->invoiceUrl === 'https://invoice.stripe.com/i/test');
    }

    public function test_a_renewal_needing_3ds_sends_the_confirm_link(): void
    {
        $this->localSubscription('sub_3ds');
        $this->stripe->respond('GET /v1/subscriptions/sub_3ds', $this->stripeSubscription('sub_3ds', 'past_due'));

        $this->postStripeWebhook($this->stripeEvent('invoice.payment_action_required', $this->invoice('sub_3ds', 'subscription_cycle')))->assertOk();

        Queue::assertPushed(SendSubscriptionPaymentIssueNotification::class, fn ($job) => $job->kind === 'action_required');
    }

    public function test_a_failed_first_payment_sends_no_email(): void
    {
        // The Payment Sheet already showed the error in the app.
        $this->localSubscription('sub_first', 'incomplete');
        $this->stripe->respond('GET /v1/subscriptions/sub_first', $this->stripeSubscription('sub_first', 'incomplete'));

        $this->postStripeWebhook($this->stripeEvent('invoice.payment_failed', $this->invoice('sub_first', 'subscription_create')))->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_an_invoice_without_a_subscription_is_ignored(): void
    {
        $this->postStripeWebhook($this->stripeEvent('invoice.paid', [
            'id' => 'in_oneoff', 'object' => 'invoice', 'billing_reason' => 'manual', 'parent' => null,
        ]))->assertOk();

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_subscription_payment_intent_is_not_mistaken_for_a_deposit(): void
    {
        $this->postStripeWebhook($this->stripeEvent('payment_intent.succeeded', [
            'id' => 'pi_invoice',
            'object' => 'payment_intent',
            'amount' => 999,
            'currency' => 'usd',
            'latest_charge' => 'ch_invoice',
            'metadata' => [],
        ]))->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertSame([], $this->stripe->requests, 'The charge should not be fetched for a non-deposit.');
    }

    public function test_replaying_the_same_event_is_harmless(): void
    {
        $object = $this->stripeSubscription('sub_twice', 'active', [
            'metadata' => ['user_id' => (string) $this->user->id, 'subscription_plan_id' => (string) $this->plan->id],
        ]);
        $this->stripe->respond('GET /v1/subscriptions/sub_twice', $object)->respond('GET /v1/subscriptions/sub_twice', $object);
        $event = $this->stripeEvent('customer.subscription.created', $object);

        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame(1, Subscription::count());
    }

    public function test_an_unsigned_event_is_rejected(): void
    {
        $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=forged',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->stripeEvent('customer.subscription.created', $this->stripeSubscription('sub_forged', 'active'))))
            ->assertStatus(400);

        $this->assertSame(0, Subscription::count());
    }
}
