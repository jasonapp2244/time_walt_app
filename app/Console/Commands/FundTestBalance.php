<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FundTestBalance extends Command
{
    protected $signature = 'stripe:fund-test-balance {amount=500 : Amount in dollars to add}';

    protected $description = 'Add test funds to Stripe platform available balance (test mode only)';

    public function handle(): int
    {
        $stripeSecret = config('services.stripe.secret');

        if (! str_starts_with($stripeSecret, 'sk_test_')) {
            $this->error('This command only works in test mode (sk_test_ keys).');

            return self::FAILURE;
        }

        \Stripe\Stripe::setApiKey($stripeSecret);

        // Show current balance
        $balance = \Stripe\Balance::retrieve();
        $available = 0;
        $pending = 0;
        foreach ($balance->available as $b) {
            if ($b->currency === 'usd') {
                $available = $b->amount / 100;
            }
        }
        foreach ($balance->pending as $b) {
            if ($b->currency === 'usd') {
                $pending = $b->amount / 100;
            }
        }

        $this->info("Current balance: \${$available} available, \${$pending} pending");

        $amount = (int) $this->argument('amount');
        $amountCents = $amount * 100;

        $this->info("Adding \${$amount} to available balance...");

        try {
            $charge = \Stripe\Charge::create([
                'amount' => $amountCents,
                'currency' => 'usd',
                'source' => 'tok_bypassPending',
                'description' => 'Test: Fund platform balance for transfers',
            ]);

            // Show updated balance
            $balance = \Stripe\Balance::retrieve();
            $newAvailable = 0;
            foreach ($balance->available as $b) {
                if ($b->currency === 'usd') {
                    $newAvailable = $b->amount / 100;
                }
            }

            $this->info("Done! New available balance: \${$newAvailable}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
