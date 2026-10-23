<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'provider' => 'stripe',
            'stripe_subscription_id' => 'sub_'.Str::random(24),
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ];
    }

    public function expired(): static
    {
        return $this->state(['status' => 'canceled', 'current_period_end' => now()->subDay(), 'canceled_at' => now()->subDay()]);
    }
}
