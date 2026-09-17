<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentHold>
 */
class PaymentHoldFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 10, 500);

        return [
            'payment_id' => Payment::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'amount' => $amount,
            'remaining_amount' => $amount,
            'hold_start_at' => now()->subDay(),
            'hold_end_at' => now()->addDay(),
            'status' => 'holding',
        ];
    }
}
