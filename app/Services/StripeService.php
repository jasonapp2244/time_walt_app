<?php

namespace App\Services;

use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StripeService
{
    /**
     * Set Stripe API key.
     */
    public function __construct()
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create Stripe Connect Express account for user.
     */
    public function createConnectAccount(User $user): array
    {
        try {
            $account = \Stripe\Account::create([
                'type' => 'express',
                'country' => 'US', // You can get this from user profile
                'email' => $user->email,
            ]);

            $connectAccount = StripeConnectAccount::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'connect_account_id' => $account->id,
                    'status' => 'pending',
                    'payouts_enabled' => false,
                    'stripe_data' => $account->toArray(),
                ]
            );

            return [
                'connect_account_id' => $account->id,
                'status' => $connectAccount->status,
                'onboarding_url' => $connectAccount->onboarding_url,
            ];
        } catch (\Exception $e) {
            Log::error('Stripe Connect Account Creation Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Get onboarding link for Stripe Connect account.
     */
    public function getOnboardingLink(StripeConnectAccount $connectAccount): string
    {
        try {
            // Use the return URL from config
            $returnUrl = config('services.stripe.connect_return_url');

            // Log the return URL being used
            Log::info('Creating Stripe AccountLink', [
                'account_id' => $connectAccount->connect_account_id,
                'return_url' => $returnUrl,
            ]);

            $accountLink = \Stripe\AccountLink::create([
                'account' => $connectAccount->connect_account_id,
                'refresh_url' => $returnUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);

            // Update onboarding URL
            $connectAccount->update([
                'onboarding_url' => $accountLink->url,
            ]);

            Log::info('Stripe AccountLink created successfully', [
                'account_id' => $connectAccount->connect_account_id,
                'account_link_url' => $accountLink->url,
                'return_url' => $returnUrl,
            ]);

            return $accountLink->url;
        } catch (\Exception $e) {
            Log::error('Stripe Onboarding Link Creation Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Create PaymentIntent with hold period data in metadata.
     */
    public function createPaymentIntent(array $data): array
    {
        try {
            // Prepare metadata for hold period
            $metadata = [
                'user_id' => $data['user_id'],
            ];

            if (isset($data['hold_period_type'])) {
                $metadata['hold_period_type'] = $data['hold_period_type'];
                $metadata['hold_start_at'] = $data['hold_start_at'] ?? now()->toIso8601String();
                $metadata['hold_end_at'] = $data['hold_end_at'] ?? now()->addDays(30)->toIso8601String();
                $metadata['hold_days'] = $data['hold_days'] ?? 30;
            }

            // Prepare PaymentIntent parameters
            $paymentIntentParams = [
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'usd',
                'metadata' => $metadata,
            ];

            // Add redirect URLs if provided (for redirect-based payment methods)
            if (isset($data['return_url'])) {
                $paymentIntentParams['return_url'] = $data['return_url'];
            }

            $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentParams);

            // Store hold period data in cache for webhook
            Cache::put(
                "payment_intent_hold_{$paymentIntent->id}",
                [
                    'hold_period_type' => $data['hold_period_type'] ?? null,
                    'hold_start_at' => $data['hold_start_at'] ?? null,
                    'hold_end_at' => $data['hold_end_at'] ?? null,
                    'hold_days' => $data['hold_days'] ?? null,
                ],
                now()->addDays(7) // Keep for 7 days
            );

            return [
                'payment_intent_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'hold_period' => $metadata['hold_period_type'] ? [
                    'type' => $metadata['hold_period_type'],
                    'start_at' => $metadata['hold_start_at'],
                    'end_at' => $metadata['hold_end_at'],
                    'days' => (int) $metadata['hold_days'],
                ] : null,
            ];
        } catch (\Exception $e) {
            Log::error('Stripe PaymentIntent Creation Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Create Stripe Transfer to user's Connect account.
     */
    public function createTransfer(PaymentHold $hold, string $type = 'manual'): Transfer
    {
        try {
            $connectAccount = StripeConnectAccount::where('user_id', $hold->user_id)->first();

            if (! $connectAccount) {
                throw new \Exception('Stripe Connect account not found for user');
            }

            // Create transfer in Stripe
            $transfer = \Stripe\Transfer::create([
                'amount' => (int) ($hold->amount * 100), // Convert to cents
                'currency' => 'usd',
                'destination' => $connectAccount->connect_account_id,
            ]);

            // Create transfer record
            $transferRecord = Transfer::create([
                'hold_id' => $hold->id,
                'user_id' => $hold->user_id,
                'stripe_transfer_id' => $transfer->id,
                'stripe_connect_account_id' => $connectAccount->connect_account_id,
                'amount' => $hold->amount,
                'currency' => 'usd',
                'status' => 'pending',
                'transfer_type' => $type,
                'admin_id' => auth()->check() && auth()->user()->role === 'admin' ? auth()->id() : null,
                'stripe_data' => $transfer->toArray(),
            ]);

            return $transferRecord;
        } catch (\Exception $e) {
            Log::error('Stripe Transfer Creation Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Get hold period data from cache or metadata.
     */
    public function getHoldPeriodData(string $paymentIntentId): ?array
    {
        // Try cache first
        $cached = Cache::get("payment_intent_hold_{$paymentIntentId}");

        if ($cached) {
            return $cached;
        }

        // Try to get from Stripe PaymentIntent metadata
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
            if (isset($paymentIntent->metadata->hold_period_type)) {
                return [
                    'hold_period_type' => $paymentIntent->metadata->hold_period_type,
                    'hold_start_at' => $paymentIntent->metadata->hold_start_at,
                    'hold_end_at' => $paymentIntent->metadata->hold_end_at,
                    'hold_days' => $paymentIntent->metadata->hold_days,
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to retrieve hold period data: '.$e->getMessage());
        }

        return null;
    }
}
