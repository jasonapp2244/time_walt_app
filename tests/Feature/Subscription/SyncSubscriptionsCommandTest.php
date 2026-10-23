<?php

namespace Tests\Feature\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * subscriptions:sync, the safety net when a renewal webhook never arrives.
 */
class SyncSubscriptionsCommandTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $user;

    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();

        $this->user = User::factory()->create();
        $this->plan = SubscriptionPlan::factory()->create(['stripe_price_id' => 'price_monthly']);
    }

    private function subscription(string $stripeId, array $attributes = []): Subscription
    {
        return Subscription::factory()->create($attributes + [
            'user_id' => $this->user->id,
            'subscription_plan_id' => $this->plan->id,
            'stripe_subscription_id' => $stripeId,
        ]);
    }

    private function stripeCopy(string $id, string $status, int $periodEnd): array
    {
        return $this->stripeSubscription($id, $status, ['items' => ['data' => [[
            'current_period_end' => $periodEnd,
            'price' => ['id' => 'price_monthly', 'object' => 'price'],
        ]]]]);
    }

    public function test_a_renewal_whose_webhook_was_missed_keeps_the_user_subscribed(): void
    {
        $subscription = $this->subscription('sub_renewed', ['current_period_end' => now()->subMinutes(10)]);
        $this->assertFalse($this->user->isSubscribed());

        $this->stripe->respond('GET /v1/subscriptions/sub_renewed', $this->stripeCopy('sub_renewed', 'active', now()->addMonth()->timestamp));

        $this->artisan('subscriptions:sync')->assertSuccessful();

        $this->assertTrue($subscription->fresh()->current_period_end->isFuture());
        $this->assertTrue($this->user->isSubscribed());
    }

    public function test_a_subscription_stripe_ended_loses_access(): void
    {
        $subscription = $this->subscription('sub_ended', ['current_period_end' => now()->addMinutes(30)]);

        $this->stripe->respond('GET /v1/subscriptions/sub_ended', $this->stripeCopy('sub_ended', 'canceled', now()->addMinutes(30)->timestamp));

        $this->artisan('subscriptions:sync')->assertSuccessful();

        $this->assertSame('canceled', $subscription->fresh()->status);
        $this->assertFalse($this->user->isSubscribed());
    }

    public function test_subscriptions_far_from_renewal_are_left_alone(): void
    {
        $this->subscription('sub_mid_period', ['current_period_end' => now()->addDays(10)]);
        $this->subscription('sub_long_gone', ['status' => 'canceled', 'current_period_end' => now()->subDay()]);
        $this->subscription('sub_fresh_attempt', ['status' => 'incomplete', 'current_period_end' => null]);

        $this->artisan('subscriptions:sync')->assertSuccessful();

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_an_unpaid_first_attempt_is_marked_expired(): void
    {
        $subscription = $this->subscription('sub_abandoned', ['status' => 'incomplete', 'current_period_end' => null]);
        $subscription->forceFill(['created_at' => now()->subDay()])->save();

        $this->stripe->respond('GET /v1/subscriptions/sub_abandoned', $this->stripeCopy('sub_abandoned', 'incomplete_expired', now()->timestamp));

        $this->artisan('subscriptions:sync')->assertSuccessful();

        $this->assertSame('incomplete_expired', $subscription->fresh()->status);
    }

    public function test_one_stripe_error_does_not_stop_the_rest(): void
    {
        $this->subscription('sub_broken', ['current_period_end' => now()->subMinutes(5)]);
        $healthy = $this->subscription('sub_healthy', ['current_period_end' => now()->subMinutes(5)]);

        $this->stripe
            ->respond('GET /v1/subscriptions/sub_broken', ['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']], 404)
            ->respond('GET /v1/subscriptions/sub_healthy', $this->stripeCopy('sub_healthy', 'active', now()->addMonth()->timestamp));

        $this->artisan('subscriptions:sync')->assertFailed();

        $this->assertTrue($healthy->fresh()->current_period_end->isFuture());
    }

    public function test_it_runs_every_hour(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('subscriptions:sync')->assertSuccessful();
    }
}
