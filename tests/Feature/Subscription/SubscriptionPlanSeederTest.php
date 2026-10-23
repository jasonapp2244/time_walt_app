<?php

namespace Tests\Feature\Subscription;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakesStripe;
use Tests\TestCase;

/**
 * The client's two auto-renewing plans, seeded into the admin panel and
 * pushed to Stripe without duplicating anything on a second run.
 */
class SubscriptionPlanSeederTest extends TestCase
{
    use FakesStripe, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStripe();
    }

    private function emptyPriceList(): array
    {
        return ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => '/v1/prices'];
    }

    private function priceList(string $id, string $product, int $amount, string $interval): array
    {
        return ['object' => 'list', 'has_more' => false, 'url' => '/v1/prices', 'data' => [[
            'id' => $id,
            'object' => 'price',
            'active' => true,
            'currency' => 'usd',
            'unit_amount' => $amount,
            'product' => $product,
            'recurring' => ['interval' => $interval],
        ]]];
    }

    /** First run on an empty Stripe account: lookup, product, price, lookup-key tag, per plan. */
    private function respondToFreshCreate(): void
    {
        // The more specific update routes first, so they are not taken by the create route.
        $this->stripe
            ->respond('POST /v1/prices/price_monthly', ['id' => 'price_monthly', 'object' => 'price'])
            ->respond('POST /v1/prices/price_yearly', ['id' => 'price_yearly', 'object' => 'price'])
            ->respond('GET /v1/prices', $this->emptyPriceList())
            ->respond('GET /v1/prices', $this->emptyPriceList())
            ->respond('POST /v1/products', ['id' => 'prod_monthly', 'object' => 'product'])
            ->respond('POST /v1/products', ['id' => 'prod_yearly', 'object' => 'product'])
            ->respond('POST /v1/prices', ['id' => 'price_monthly', 'object' => 'price'])
            ->respond('POST /v1/prices', ['id' => 'price_yearly', 'object' => 'price']);
    }

    public function test_it_creates_a_monthly_and_a_yearly_auto_renewing_plan(): void
    {
        $this->respondToFreshCreate();

        $this->seed(SubscriptionPlanSeeder::class);

        $monthly = SubscriptionPlan::where('interval', 'month')->sole();
        $yearly = SubscriptionPlan::where('interval', 'year')->sole();

        $this->assertSame(['Monthly', '4.99', 'usd', 'price_monthly', true], [$monthly->name, $monthly->price, $monthly->currency, $monthly->stripe_price_id, $monthly->is_active]);
        $this->assertSame(['Yearly', '49.99', 'usd', 'price_yearly', true], [$yearly->name, $yearly->price, $yearly->currency, $yearly->stripe_price_id, $yearly->is_active]);
        $this->assertSame('No commitments. Cancel anytime.', $monthly->description);
        $this->assertContains('Renews automatically', $yearly->features);

        // Recurring prices, so Stripe renews them on its own.
        $created = $this->stripe->requestsTo('POST', '/v1/prices');
        $this->assertSame(['interval' => 'month'], $created[0]['params']['recurring']);
        $this->assertSame(499, $created[0]['params']['unit_amount']);

        $tags = array_values(array_filter($created, fn ($r) => isset($r['params']['lookup_key'])));
        $this->assertSame(['time_vault_monthly', 'time_vault_yearly'], array_column(array_column($tags, 'params'), 'lookup_key'));
    }

    public function test_the_app_offers_monthly_then_yearly(): void
    {
        $this->respondToFreshCreate();
        $this->seed(SubscriptionPlanSeeder::class);

        Sanctum::actingAs(User::factory()->create());

        $plans = $this->getJson('/api/subscription/plans')->assertOk()->json('data.plans');

        $this->assertSame(['Monthly', 'Yearly'], array_column($plans, 'name'));
        $this->assertSame([4.99, 49.99], array_column($plans, 'price'));
        $this->assertSame(['month', 'year'], array_column($plans, 'interval'));
    }

    public function test_running_it_again_creates_nothing_new(): void
    {
        SubscriptionPlan::factory()->create(['name' => 'Old name', 'price' => 4.99, 'interval' => 'month', 'stripe_product_id' => 'prod_m', 'stripe_price_id' => 'price_m']);
        SubscriptionPlan::factory()->create(['price' => 49.99, 'interval' => 'year', 'stripe_product_id' => 'prod_y', 'stripe_price_id' => 'price_y']);

        $this->stripe
            ->respond('GET /v1/prices', $this->priceList('price_m', 'prod_m', 499, 'month'))
            ->respond('GET /v1/prices', $this->priceList('price_y', 'prod_y', 4999, 'year'))
            ->respond('POST /v1/products/prod_m', ['id' => 'prod_m', 'object' => 'product'])
            ->respond('POST /v1/products/prod_y', ['id' => 'prod_y', 'object' => 'product']);

        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(2, SubscriptionPlan::count());
        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/prices'));
        $this->assertSame('Monthly', SubscriptionPlan::where('stripe_price_id', 'price_m')->value('name'));
    }

    public function test_a_price_another_server_already_made_is_reused(): void
    {
        $this->stripe
            ->respond('GET /v1/prices', $this->priceList('price_shared_m', 'prod_shared_m', 499, 'month'))
            ->respond('GET /v1/prices', $this->priceList('price_shared_y', 'prod_shared_y', 4999, 'year'))
            ->respond('POST /v1/products/prod_shared_m', ['id' => 'prod_shared_m', 'object' => 'product'])
            ->respond('POST /v1/products/prod_shared_y', ['id' => 'prod_shared_y', 'object' => 'product']);

        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(['price_shared_m', 'price_shared_y'], SubscriptionPlan::orderBy('sort_order')->pluck('stripe_price_id')->all());
        $this->assertSame(['prod_shared_m', 'prod_shared_y'], SubscriptionPlan::orderBy('sort_order')->pluck('stripe_product_id')->all());
        $this->assertSame([], $this->stripe->requestsTo('POST', '/v1/prices'));
        $this->assertTrue(SubscriptionPlan::available()->count() === 2);
    }

    public function test_a_stripe_failure_leaves_drafts_that_a_rerun_completes(): void
    {
        $error = ['error' => ['type' => 'api_error', 'message' => 'Stripe is down']];
        $this->stripe
            ->respond('GET /v1/prices', $this->emptyPriceList())
            ->respond('GET /v1/prices', $this->emptyPriceList())
            ->respond('POST /v1/products', $error, 500)
            ->respond('POST /v1/products', $error, 500);

        try {
            $this->seed(SubscriptionPlanSeeder::class);
            $this->fail('The seeder should report the Stripe failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Monthly, Yearly', $e->getMessage());
        }

        $this->assertSame(2, SubscriptionPlan::count());
        $this->assertSame(0, SubscriptionPlan::available()->count());

        $this->fakeStripe();
        $this->respondToFreshCreate();
        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(2, SubscriptionPlan::count());
        $this->assertSame(2, SubscriptionPlan::available()->count());
    }

    public function test_plans_left_from_another_stripe_account_are_linked_again(): void
    {
        $old = SubscriptionPlan::factory()->create(['name' => 'Premium Monthly', 'price' => 4.99, 'interval' => 'month', 'stripe_product_id' => 'prod_old_m', 'stripe_price_id' => 'price_old_m']);
        SubscriptionPlan::factory()->create(['name' => 'Premium Yearly', 'price' => 49.99, 'interval' => 'year', 'stripe_product_id' => 'prod_old_y', 'stripe_price_id' => 'price_old_y']);
        $missing = fn (string $id) => ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such price: '{$id}'"]];

        $this->stripe
            ->respond('GET /v1/prices/price_old_m', $missing('price_old_m'), 404)
            ->respond('GET /v1/prices/price_old_y', $missing('price_old_y'), 404);
        $this->respondToFreshCreate();

        $this->seed(SubscriptionPlanSeeder::class);

        $this->assertSame(2, SubscriptionPlan::count());
        $this->assertSame(['price_monthly', 'price_yearly'], SubscriptionPlan::orderBy('sort_order')->pluck('stripe_price_id')->all());
        $this->assertSame('Monthly', $old->fresh()->name);
        $this->assertSame(2, SubscriptionPlan::available()->count());
    }

    public function test_it_refuses_to_run_without_a_stripe_key(): void
    {
        config(['services.stripe.secret' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('STRIPE_SECRET is not set');

        try {
            $this->seed(SubscriptionPlanSeeder::class);
        } finally {
            $this->assertSame([], $this->stripe->requests);
            $this->assertSame(0, SubscriptionPlan::count());
        }
    }
}
