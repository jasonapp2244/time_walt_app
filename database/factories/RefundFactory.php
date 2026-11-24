<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 10, 500);

        return [
            'payment_id' => Payment::factory(),
            'stripe_refund_id' => 're_'.Str::random(24),
            'amount' => $amount,
            'vault_amount' => $amount,
            'reversed_amount' => 0,
            'currency' => 'usd',
            'card_brand' => 'visa',
            'card_last4' => '4242',
            'reason_code' => 'requested_by_customer',
            'reason_note' => 'Customer asked for the deposit back.',
            'source' => 'admin',
            'status' => 'succeeded',
            'refunded_at' => now(),
        ];
    }
}
