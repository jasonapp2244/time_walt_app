<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserBankAccount>
 */
class UserBankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_holder_name' => fake()->name(),
            'bank_name' => fake()->company().' Bank',
            'account_number' => (string) fake()->numberBetween(10000000, 99999999),
            'routing_number' => (string) fake()->numberBetween(100000000, 999999999),
            'account_type' => 'checking',
            'country' => 'US',
            'currency' => 'usd',
            'stripe_bank_account_id' => 'ba_'.Str::random(20),
            'is_primary' => true,
        ];
    }
}
