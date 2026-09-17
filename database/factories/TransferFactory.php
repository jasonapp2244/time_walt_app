<?php

namespace Database\Factories;

use App\Models\PaymentHold;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transfer>
 */
class TransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'hold_id' => PaymentHold::factory(),
            'user_id' => User::factory(),
            'stripe_transfer_id' => 'tr_'.Str::random(24),
            'stripe_connect_account_id' => 'acct_'.Str::random(16),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'usd',
            'status' => 'completed',
            'transferred_at' => now(),
        ];
    }
}
