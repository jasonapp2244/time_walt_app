<?php

namespace Tests\Feature\Subscription;

use App\Models\StripeCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * The subscribe → pay → confirm → retry journey the app runs after a 402.
 */
class SubscriptionApiTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        config(['app.free_lock_limit' => 2]);

        $this->user = User::factory()->create(['timezone' => 'UTC']);
        $this->plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_premium', 'price' => 9.99]);
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->actingAs($this->user, 'sanctum')->json($method, $uri, $data);
    }

    private function existingCustomer(): void
    {
        StripeCustomer::create(['user_id' => $this->user->id, 'stripe_customer_id' => 'cus_fake']);
    }

    public function test_plans_lists_only_visible_synced_plans_in_order(): void
    {
        SubscriptionPlan::factory()->create(['name' => 'Yearly', 'interval' => 'year', 'price' => 99, 'sort_order' => 2]);
        SubscriptionPlan::factory()->create(['name' => 'Hidden', 'is_active' => false]);
        SubscriptionPlan::factory()->create(['name' => 'Unsynced', 'stripe_price_id' => null]);

        $this->api('GET', '/api/subscription/plans')
            ->assertOk()
            ->assertJsonCount(2, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Premium')
            ->assertJsonPath('data.plans.0.price', 9.99)
            ->assertJsonPath('data.plans.0.currency', 'USD')
            ->assertJsonPath('data.plans.1.name', 'Yearly');
    }

    public function test_status_reports_the_free_allowance_left(): void
    {
        $this->user->forceFill(['free_locks_used' => 1])->save();

        $this->api('GET', '/api/subscription/status')
            ->assertOk()
            ->assertJsonPath('data.is_subscribed', false)
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.locks_used', 1)
            ->assertJsonPath('data.locks_left', 1)
            ->assertJsonPath('data.withdrawals_left', 2);
    }

    public function test_subscribe_returns_payment_sheet_keys_from_the_first_invoice(): void
    {
        $this->existingCustomer();
        $this->stripe->respond('POST /v1/subscriptions', $this->stripeSubscription('sub_new', 'incomplete', [
            'metadata' => ['user_id' => (string) $this->user->id, 'subscription_plan_id' => (string) $this->plan->id],
            'latest_invoice' => [
                'id' => 'in_first',
                'object' => 'invoice',
                'confirmation_secret' => ['client_secret' => 'pi_first_secret_abc', 'type' => 'payment_intent'],
            ],
        ]));
        $this->stripe->respond('POST /v1/ephemeral_keys', ['id' => 'ephkey_1', 'object' => 'ephemeral_key', 'secret' => 'ek_test_1']);

        $this->api('POST', '/api/subscription/subscribe', ['plan_id' => $this->plan->id])
            ->assertOk()
            ->assertJsonPath('data.client_secret', 'pi_first_secret_abc')
            ->assertJsonPath('data.customer_id', 'cus_fake')
            ->assertJsonPath('data.ephemeral_key', 'ek_test_1')
            ->assertJsonPath('data.publishable_key', 'pk_test_fake')
            ->assertJsonPath('data.status', 'incomplete');

        $sent = $this->stripe->requestsTo('POST', '/v1/subscriptions')[0]['params'];
        $this->assertSame('cus_fake', $sent['customer']);
        $this->assertSame('price_premium', $sent['items'][0]['price']);
        $this->assertSame('default_incomplete', $sent['payment_behavior']);
        $this->assertSame('on_subscription', $sent['payment_settings']['save_default_payment_method']);
        $this->assertSame(['latest_invoice.confirmation_secret'], $sent['expand']);

        $subscription = Subscription::sole();
        $this->assertSame('incomplete', $subscription->status);
        $this->assertSame('sub_new', $subscription->stripe_subscription_id);
        $this->assertFalse($this->user->isSubscribed());
    }

    public function test_tapping_subscribe_again_reuses_the_unpaid_subscription(): void
    {
        $this->existingCustomer();
        Subscription::factory()->create([
            'user_id' => $this->user->id,
            'subscription_plan_id' => $this->plan->id,
            'stripe_subscription_id' => 'sub_open',
            'status' => 'incomplete',
        ]);
        $this->stripe->respond('GET /v1/subscriptions/sub_open', $this->stripeSubscription('sub_open', 'incomplete', [
            'latest_invoice' => ['id' => 'in_1', 'object' => 'invoice', 'confirmation_secret' => ['client_secret' => 'pi_open_secret']],
        ]));
        $this->stripe->respond('POST /v1/ephemeral_keys', ['id' => 'ephkey_2', 'object' => 'ephemeral_key', 'secret' => 'ek_test_2']);

        $this->api('POST', '/api/subscription/subscribe', ['plan_id' => $this->plan->id])
            ->assertOk()
            ->assertJsonPath('data.client_secret', 'pi_open_secret');

        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/subscriptions'));
        $this->assertSame(1, Subscription::count());
    }

    public function test_an_active_subscriber_cannot_subscribe_twice(): void
    {
        Subscription::factory()->create(['user_id' => $this->user->id]);

        $this->api('POST', '/api/subscription/subscribe', ['plan_id' => $this->plan->id])
            ->assertStatus(409);

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_hidden_plan_cannot_be_bought(): void
    {
        $hidden = SubscriptionPlan::factory()->create(['is_active' => false]);

        $this->api('POST', '/api/subscription/subscribe', ['plan_id' => $hidden->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id' => 'This plan is no longer available.']);
    }

    public function test_confirm_activates_from_stripe_so_the_blocked_lock_works_straight_away(): void
    {
        $this->user->forceFill(['free_locks_used' => 2])->save();
        Subscription::factory()->create([
            'user_id' => $this->user->id,
            'subscription_plan_id' => $this->plan->id,
            'stripe_subscription_id' => 'sub_paid',
            'status' => 'incomplete',
            'current_period_end' => null,
        ]);
        $periodEnd = now()->addMonth()->startOfSecond();
        $this->stripe->respond('GET /v1/subscriptions/sub_paid', $this->stripeSubscription('sub_paid', 'active', [
            'items' => ['data' => [['current_period_end' => $periodEnd->timestamp]]],
        ]));

        $this->api('POST', '/api/subscription/confirm')
            ->assertOk()
            ->assertJsonPath('message', 'Your subscription is active.')
            ->assertJsonPath('data.is_subscribed', true)
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.current_period_end', $periodEnd->toIso8601String())
            ->assertJsonPath('data.locks_left', null);

        $this->assertTrue($this->user->fresh()->isSubscribed());
    }

    public function test_confirm_while_payment_is_still_processing(): void
    {
        Subscription::factory()->create([
            'user_id' => $this->user->id,
            'subscription_plan_id' => $this->plan->id,
            'stripe_subscription_id' => 'sub_slow',
            'status' => 'incomplete',
        ]);
        $this->stripe->respond('GET /v1/subscriptions/sub_slow', $this->stripeSubscription('sub_slow', 'incomplete'));

        $this->api('POST', '/api/subscription/confirm')
            ->assertOk()
            ->assertJsonPath('data.is_subscribed', false)
            ->assertJsonPath('message', 'Payment is still processing. Check again in a few seconds.');
    }

    public function test_confirm_without_any_subscription_is_404(): void
    {
        $this->api('POST', '/api/subscription/confirm')->assertNotFound();
    }

    public function test_cancel_keeps_access_until_the_period_ends(): void
    {
        Subscription::factory()->create([
            'user_id' => $this->user->id,
            'subscription_plan_id' => $this->plan->id,
            'stripe_subscription_id' => 'sub_live',
        ]);
        $this->stripe->respond('POST /v1/subscriptions/sub_live', $this->stripeSubscription('sub_live', 'active', [
            'cancel_at_period_end' => true,
        ]));

        $this->api('POST', '/api/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.is_subscribed', true)
            ->assertJsonPath('data.subscription.cancel_at_period_end', true);

        $this->assertSame('true', $this->stripe->requestsTo('POST', '/v1/subscriptions/sub_live')[0]['params']['cancel_at_period_end']);
    }

    public function test_cancel_without_a_subscription_is_404(): void
    {
        $this->api('POST', '/api/subscription/cancel')->assertNotFound();
    }

    public function test_portal_returns_a_stripe_link(): void
    {
        $this->existingCustomer();
        $this->stripe->respond('POST /v1/billing_portal/sessions', [
            'id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/test_1',
        ]);

        $this->api('POST', '/api/subscription/portal')
            ->assertOk()
            ->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/test_1');
    }

    public function test_a_stripe_failure_on_subscribe_is_reported_without_a_local_row(): void
    {
        $this->existingCustomer();
        $this->stripe->respond('POST /v1/subscriptions', [
            'error' => ['type' => 'invalid_request_error', 'message' => 'No such price'],
        ], 400);

        $this->api('POST', '/api/subscription/subscribe', ['plan_id' => $this->plan->id])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->assertSame(0, Subscription::count());
    }

    public function test_every_endpoint_requires_a_signed_in_user(): void
    {
        $this->getJson('/api/subscription/plans')->assertUnauthorized();
        $this->getJson('/api/subscription/status')->assertUnauthorized();
        $this->postJson('/api/subscription/subscribe', ['plan_id' => $this->plan->id])->assertUnauthorized();
    }
}
