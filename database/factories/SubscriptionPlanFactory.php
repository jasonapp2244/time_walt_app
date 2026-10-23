<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Premium',
            'description' => 'Unlimited locks and withdrawals.',
            'features' => ['Unlimited locks', 'Unlimited withdrawals'],
            'price' => 9.99,
            'currency' => 'usd',
            'interval' => 'month',
            'stripe_product_id' => 'prod_'.Str::random(14),
            'stripe_price_id' => 'price_'.Str::random(24),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
