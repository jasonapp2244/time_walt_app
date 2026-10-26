<?php

namespace Tests\Feature\Subscription;

use App\Mail\SubscriptionPaymentIssueMail;
use App\Models\AppSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\FreeTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

class AdminSubscriptionTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function planForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Premium',
            'description' => 'Everything unlimited',
            'features' => "Unlimited locks\n\nUnlimited withdrawals\n",
            'price' => '9.99',
            'currency' => 'usd',
            'interval' => 'month',
            'sort_order' => 1,
            'is_active' => '1',
        ], $overrides);
    }

    // ── Plans ────────────────────────────────────────────────────────────────

    public function test_creating_a_plan_creates_its_stripe_product_and_recurring_price(): void
    {
        $this->stripe->respond('POST /v1/products', ['id' => 'prod_new', 'object' => 'product']);
        $this->stripe->respond('POST /v1/prices', ['id' => 'price_new', 'object' => 'price']);

        $this->actingAs($this->admin)->post('/admin/subscription-plans', $this->planForm())
            ->assertRedirect(route('admin.subscription-plans.index'));

        $plan = SubscriptionPlan::sole();
        $this->assertSame('prod_new', $plan->stripe_product_id);
        $this->assertSame('price_new', $plan->stripe_price_id);
        $this->assertTrue($plan->is_active);
        $this->assertSame(['Unlimited locks', 'Unlimited withdrawals'], $plan->features);

        $price = $this->stripe->requestsTo('POST', '/v1/prices')[0]['params'];
        $this->assertSame(999, $price['unit_amount']);
        $this->assertSame('usd', $price['currency']);
        $this->assertSame('month', $price['recurring']['interval']);
        $this->assertSame('prod_new', $price['product']);
    }

    public function test_a_plan_stripe_rejects_is_kept_as_a_hidden_draft(): void
    {
        $this->stripe->respond('POST /v1/products', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);
        $this->stripe->respond('POST /v1/products', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);
        $this->stripe->respond('POST /v1/products', ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']], 500);

        $response = $this->actingAs($this->admin)->post('/admin/subscription-plans', $this->planForm());

        $plan = SubscriptionPlan::sole();
        $response->assertRedirect(route('admin.subscription-plans.edit', $plan))->assertSessionHasErrors('stripe');
        $this->assertFalse($plan->is_active);
        $this->assertNull($plan->stripe_price_id);
    }

    public function test_a_price_change_creates_a_new_price_and_archives_the_old_one(): void
    {
        $plan = SubscriptionPlan::factory()->create(['stripe_product_id' => 'prod_x', 'stripe_price_id' => 'price_old', 'price' => 9.99]);
        $this->stripe->respond('POST /v1/products/prod_x', ['id' => 'prod_x', 'object' => 'product']);
        $this->stripe->respond('POST /v1/prices/price_old', ['id' => 'price_old', 'object' => 'price', 'active' => false]);
        $this->stripe->respond('POST /v1/prices', ['id' => 'price_new', 'object' => 'price']);

        $this->actingAs($this->admin)->put("/admin/subscription-plans/{$plan->id}", $this->planForm(['price' => '14.99']))
            ->assertRedirect(route('admin.subscription-plans.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'New subscribers pay the new price'));

        $this->assertSame('price_new', $plan->fresh()->stripe_price_id);
        $this->assertSame('false', $this->stripe->requestsTo('POST', '/v1/prices/price_old')[0]['params']['active']);
    }

    public function test_a_plan_with_subscribers_cannot_change_price(): void
    {
        $plan = SubscriptionPlan::factory()->create(['stripe_product_id' => 'prod_x', 'stripe_price_id' => 'price_old', 'price' => 9.99]);
        Subscription::factory()->create(['subscription_plan_id' => $plan->id]);

        $this->actingAs($this->admin)->from("/admin/subscription-plans/{$plan->id}/edit")
            ->put("/admin/subscription-plans/{$plan->id}", $this->planForm(['price' => '14.99']))
            ->assertSessionHasErrors('price');

        $this->assertEquals(9.99, $plan->fresh()->price);
        $this->assertSame('price_old', $plan->fresh()->stripe_price_id);
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_a_name_change_only_updates_the_product(): void
    {
        $plan = SubscriptionPlan::factory()->create(['stripe_product_id' => 'prod_x', 'stripe_price_id' => 'price_keep', 'price' => 9.99]);
        $this->stripe->respond('POST /v1/products/prod_x', ['id' => 'prod_x', 'object' => 'product']);

        $this->actingAs($this->admin)->put("/admin/subscription-plans/{$plan->id}", $this->planForm(['name' => 'Gold']))
            ->assertRedirect(route('admin.subscription-plans.index'));

        $this->assertSame('Gold', $plan->fresh()->name);
        $this->assertSame('price_keep', $plan->fresh()->stripe_price_id);
        $this->assertCount(1, $this->stripe->requests);
    }

    public function test_an_edit_stripe_rejects_is_not_saved(): void
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Premium', 'stripe_product_id' => 'prod_x']);
        $this->stripe->respond('POST /v1/products/prod_x', ['error' => ['type' => 'invalid_request_error', 'message' => 'No such product']], 400);

        $this->actingAs($this->admin)->from("/admin/subscription-plans/{$plan->id}/edit")
            ->put("/admin/subscription-plans/{$plan->id}", $this->planForm(['name' => 'Renamed']))
            ->assertSessionHasErrors('stripe');

        $this->assertSame('Premium', $plan->fresh()->name);
        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_hiding_a_plan_deactivates_the_stripe_product(): void
    {
        $plan = SubscriptionPlan::factory()->create(['stripe_product_id' => 'prod_x']);
        $this->stripe->respond('POST /v1/products/prod_x', ['id' => 'prod_x', 'object' => 'product']);

        $this->actingAs($this->admin)->post("/admin/subscription-plans/{$plan->id}/toggle")->assertRedirect();

        $this->assertFalse($plan->fresh()->is_active);
        $this->assertSame('false', $this->stripe->requests[0]['params']['active']);
    }

    public function test_a_plan_with_active_subscribers_cannot_be_deleted(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        Subscription::factory()->create(['subscription_plan_id' => $plan->id]);

        $this->actingAs($this->admin)->delete("/admin/subscription-plans/{$plan->id}")->assertSessionHasErrors('plan');

        $this->assertNotSoftDeleted($plan);
    }

    // ── Free tier settings ───────────────────────────────────────────────────

    public function test_free_tier_settings_change_the_live_limits(): void
    {
        $this->actingAs($this->admin)->post('/admin/settings/free-tier', [
            'free_lock_limit' => 5,
            'free_withdraw_limit' => 3,
            'withdraw_limit_enabled' => '0',
        ])->assertRedirect(route('admin.settings.free-tier'));

        $freeTier = app(FreeTierService::class);
        $this->assertSame(5, $freeTier->limit(FreeTierService::LOCK));
        $this->assertNull($freeTier->limit(FreeTierService::WITHDRAW));
        $this->assertSame('3', AppSetting::where('key', 'free_withdraw_limit')->value('value'));
    }

    // ── Subscriptions list & actions ─────────────────────────────────────────

    public function test_admin_can_cancel_a_subscription_at_period_end(): void
    {
        $subscription = Subscription::factory()->create(['stripe_subscription_id' => 'sub_admin']);
        $this->stripe->respond('POST /v1/subscriptions/sub_admin', $this->stripeSubscription('sub_admin', 'active', ['cancel_at_period_end' => true]));

        $this->actingAs($this->admin)->post("/admin/subscriptions/{$subscription->id}/cancel")
            ->assertSessionHas('success');

        $this->assertTrue($subscription->fresh()->cancel_at_period_end);
    }

    public function test_admin_can_end_a_subscription_now(): void
    {
        $subscription = Subscription::factory()->create(['stripe_subscription_id' => 'sub_kill']);
        $this->stripe->respond('GET /v1/subscriptions/sub_kill', $this->stripeSubscription('sub_kill', 'active'));
        $this->stripe->respond('DELETE /v1/subscriptions/sub_kill', $this->stripeSubscription('sub_kill', 'canceled', ['canceled_at' => now()->timestamp]));

        $this->actingAs($this->admin)->post("/admin/subscriptions/{$subscription->id}/cancel-now")
            ->assertSessionHas('success');

        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    public function test_the_list_filters_and_reports_mrr_per_currency(): void
    {
        $usd = SubscriptionPlan::factory()->create(['price' => 10, 'currency' => 'usd']);
        $yearlyEur = SubscriptionPlan::factory()->create(['price' => 120, 'currency' => 'eur', 'interval' => 'year']);
        Subscription::factory()->count(2)->create(['subscription_plan_id' => $usd->id]);
        Subscription::factory()->create(['subscription_plan_id' => $yearlyEur->id]);
        Subscription::factory()->create(['subscription_plan_id' => $usd->id, 'cancel_at_period_end' => true]);
        Subscription::factory()->expired()->create(['subscription_plan_id' => $usd->id]);

        $response = $this->actingAs($this->admin)->get('/admin/subscriptions?status=active');

        $response->assertOk()->assertSee('USD 20.00 · EUR 10.00');
        $this->assertSame(4, $response->viewData('subscriptions')->total());
        $this->assertEquals(['active' => 4, 'past_due' => 0, 'canceling' => 1, 'canceled' => 1, 'mrr' => ['USD' => 20.0, 'EUR' => 10.0], 'total' => 5],
            $this->actingAs($this->admin)->getJson('/admin/subscriptions/stats')->json());
    }

    public function test_admin_can_reset_a_users_free_usage(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['free_locks_used' => 2, 'free_withdrawals_used' => 2])->save();

        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/reset-free-usage")->assertSessionHas('success');

        $this->assertSame(0, $user->fresh()->free_locks_used);
        $this->assertSame(0, $user->fresh()->free_withdrawals_used);
    }

    // ── Pages & access ───────────────────────────────────────────────────────

    public function test_every_new_admin_page_renders(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $subscriber = User::factory()->create();
        Subscription::factory()->create(['user_id' => $subscriber->id, 'subscription_plan_id' => $plan->id]);

        foreach ([
            '/admin/subscription-plans',
            '/admin/subscription-plans/create',
            "/admin/subscription-plans/{$plan->id}/edit",
            '/admin/subscriptions',
            '/admin/settings/free-tier',
            "/admin/users/{$subscriber->id}",
            '/admin/dashboard',
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_a_normal_user_cannot_reach_the_subscription_admin(): void
    {
        $user = User::factory()->create();

        foreach (['/admin/subscription-plans', '/admin/subscriptions', '/admin/settings/free-tier'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('admin.login'));
        }

        $this->actingAs($user)->post('/admin/subscription-plans', $this->planForm())->assertRedirect(route('admin.login'));
        $this->assertSame(0, SubscriptionPlan::count());
    }

    public function test_the_payment_issue_email_renders_with_the_pay_link(): void
    {
        $subscription = Subscription::factory()->create();

        $html = (new SubscriptionPaymentIssueMail($subscription, 'action_required', 'https://invoice.stripe.com/i/x'))->render();

        $this->assertStringContainsString('Confirm payment', $html);
        $this->assertStringContainsString('https://invoice.stripe.com/i/x', $html);
        $this->assertStringContainsString($subscription->plan->name, $html);
    }
}
