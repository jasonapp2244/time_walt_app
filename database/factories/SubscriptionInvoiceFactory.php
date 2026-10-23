<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubscriptionInvoice>
 */
class SubscriptionInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'user_id' => User::factory(),
            'stripe_invoice_id' => 'in_'.Str::random(24),
            'invoice_number' => 'TV-'.fake()->numerify('####-####'),
            'billing_reason' => 'subscription_create',
            'amount' => 4.99,
            'currency' => 'usd',
            'period_end' => now()->addMonth(),
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/test',
            'invoice_pdf' => 'https://pay.stripe.com/invoice/test/pdf',
            'paid_at' => now(),
        ];
    }

    public function renewal(): static
    {
        return $this->state(['billing_reason' => 'subscription_cycle']);
    }
}
