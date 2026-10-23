<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * The client's subscription plans (2026-10-23): $4.99 a month and $49.99 a
 * year, both renewing automatically until cancelled.
 *
 * Calls Stripe, so it is not part of DatabaseSeeder. Run it on purpose:
 *   php artisan db:seed --class=SubscriptionPlanSeeder --force
 *
 * Safe to run again on any machine. Each plan's Stripe price carries a
 * lookup key, so a second run, or another server on the same Stripe account,
 * reuses that price instead of creating a duplicate product.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public const CURRENCY = 'usd';

    public const PLANS = [
        [
            'lookup_key' => 'time_vault_monthly',
            'name' => 'Monthly',
            'price' => '4.99',
            'interval' => 'month',
            'sort_order' => 1,
        ],
        [
            'lookup_key' => 'time_vault_yearly',
            'name' => 'Yearly',
            'price' => '49.99',
            'interval' => 'year',
            'sort_order' => 2,
        ],
    ];

    public const DESCRIPTION = 'No commitments. Cancel anytime.';

    public const FEATURES = [
        'Unlimited time locks',
        'Unlimited withdrawals',
        'Renews automatically',
        'No commitments. Cancel anytime.',
    ];

    public function run(SubscriptionService $subscriptions): void
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw new \RuntimeException('STRIPE_SECRET is not set. Add it to .env, run `php artisan config:cache`, then run this seeder again.');
        }

        $this->command?->info('Stripe '.(str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_') ? 'LIVE' : 'test').' mode');

        $failed = [];

        foreach (self::PLANS as $definition) {
            try {
                $this->seedPlan($subscriptions, $definition);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Subscription plan seeding failed', ['plan' => $definition['name'], 'error' => $e->getMessage()]);
                $this->command?->error("{$definition['name']}: Stripe rejected it: {$e->getMessage()}");
                $failed[] = $definition['name'];
            }
        }

        if ($failed !== []) {
            throw new \RuntimeException('Not synced to Stripe: '.implode(', ', $failed).'. A plan without a Stripe price is never offered in the app; fix the cause and run the seeder again.');
        }
    }

    private function seedPlan(SubscriptionService $subscriptions, array $definition): void
    {
        $stripePrice = $this->findStripePrice($definition);
        $plan = $this->findLocalPlan($definition, $stripePrice) ?? new SubscriptionPlan;

        if (! $plan->exists) {
            // Saved inactive first, as the admin form does, so the plan has an
            // ID for Stripe metadata and is never offered before it is priced.
            $plan->fill([
                'name' => $definition['name'],
                'price' => $definition['price'],
                'currency' => self::CURRENCY,
                'interval' => $definition['interval'],
                'is_active' => false,
            ])->save();
        }

        // Linked to a price this Stripe account does not have, e.g. made
        // before the keys were switched to another account: link it again.
        if ($plan->stripe_price_id && $stripePrice?->id !== $plan->stripe_price_id && ! $this->priceExists($plan->stripe_price_id)) {
            $this->command?->warn("{$definition['name']}: its Stripe price is not in this Stripe account; linking it again.");
            $plan->forceFill(['stripe_price_id' => null, 'stripe_product_id' => null])->save();
        }

        if (! $plan->stripe_price_id && $stripePrice) {
            $plan->stripe_price_id = $stripePrice->id;
            $plan->stripe_product_id = is_string($stripePrice->product) ? $stripePrice->product : $stripePrice->product->id;
            $plan->save();
            $action = 'adopted the existing Stripe price';
        } else {
            $action = $plan->stripe_price_id ? 'updated' : 'created';
        }

        $plan->fill([
            'name' => $definition['name'],
            'description' => self::DESCRIPTION,
            'features' => self::FEATURES,
            'sort_order' => $definition['sort_order'],
            'is_active' => true,
        ]);

        $subscriptions->syncPlanToStripe($plan);

        if ($stripePrice?->id !== $plan->stripe_price_id) {
            \Stripe\Price::update($plan->stripe_price_id, [
                'lookup_key' => $definition['lookup_key'],
                'transfer_lookup_key' => true,
            ]);
        }

        $this->command?->info("{$plan->name}: \${$plan->price} / {$plan->interval}, {$action} ({$plan->stripe_price_id})");
    }

    /**
     * The Stripe price already carrying this plan's lookup key, if it still
     * charges the same amount on the same schedule.
     */
    private function findStripePrice(array $definition): ?\Stripe\Price
    {
        $price = \Stripe\Price::all(['lookup_keys' => [$definition['lookup_key']], 'limit' => 1])->data[0] ?? null;

        $matches = $price
            && $price->active
            && $price->currency === self::CURRENCY
            && (int) $price->unit_amount === (int) round((float) $definition['price'] * 100)
            && ($price->recurring->interval ?? null) === $definition['interval'];

        return $matches ? $price : null;
    }

    private function priceExists(string $priceId): bool
    {
        try {
            \Stripe\Price::retrieve($priceId);

            return true;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return false;
            }

            throw $e;
        }
    }

    /**
     * The plan row this definition already has: the one on the Stripe price,
     * else one with the same price and schedule (including a draft left by a
     * failed run), so running the seeder again never duplicates a plan.
     */
    private function findLocalPlan(array $definition, ?\Stripe\Price $stripePrice): ?SubscriptionPlan
    {
        if ($stripePrice) {
            $plan = SubscriptionPlan::where('stripe_price_id', $stripePrice->id)->first();

            if ($plan) {
                return $plan;
            }
        }

        return SubscriptionPlan::where('currency', self::CURRENCY)
            ->where('interval', $definition['interval'])
            ->where('price', $definition['price'])
            ->orderByRaw('stripe_price_id is null')
            ->orderBy('id')
            ->first();
    }
}
