<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'payment_intent_id' => 'pi_'.Str::random(24),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'status' => 'succeeded',
            'paid_at' => now(),
            'card_brand' => 'visa',
            'card_last4' => '4242',
        ];
    }
}
