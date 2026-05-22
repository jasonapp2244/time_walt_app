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
     * Create Stripe Custom Connect account for user (silent — no onboarding redirect).
     */
    public function createConnectAccount(User $user, array $data = []): array
    {
        try {
            $nameParts = explode(' ', $user->full_name ?? 'User', 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            $country = strtoupper($data['country'] ?? 'US');

            $accountParams = [
                'type' => 'custom',
                'country' => $country,
                'email' => $user->email,
                'capabilities' => [
                    'transfers' => ['requested' => true],
                ],
                'individual' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $user->email,
                ],
                'business_type' => 'individual',
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'platform' => 'time_vault',
                ],
            ];

            // Add DOB if provided
            if (! empty($data['dob'])) {
                $dob = \Carbon\Carbon::parse($data['dob']);
                $accountParams['individual']['dob'] = [
                    'day' => $dob->day,
                    'month' => $dob->month,
                    'year' => $dob->year,
                ];
            }

            // Add TOS acceptance if IP provided
            if (! empty($data['ip'])) {
                $accountParams['tos_acceptance'] = [
                    'date' => time(),
                    'ip' => $data['ip'],
                ];
            }

            $account = \Stripe\Account::create($accountParams);

            $connectAccount = StripeConnectAccount::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'connect_account_id' => $account->id,
                    'status' => 'verified',
                    'payouts_enabled' => true,
                    'stripe_data' => $account->toArray(),
                    'verified_at' => now(),
                ]
            );

            return [
                'connect_account_id' => $account->id,
                'status' => $connectAccount->status,
            ];
        } catch (\Exception $e) {
            Log::error('Stripe Custom Connect Account Creation Failed: '.$e->getMessage());
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
            $baseReturnUrl = config('services.stripe.connect_return_url');

            // Append account ID to return URL to ensure we have it in callback
            // Stripe will also add its own parameters, but this ensures we have the account ID
            $separator = parse_url($baseReturnUrl, PHP_URL_QUERY) ? '&' : '?';
            $returnUrl = $baseReturnUrl.$separator.'account='.urlencode($connectAccount->connect_account_id);
            $refreshUrl = $baseReturnUrl.$separator.'account='.urlencode($connectAccount->connect_account_id);

            // Store account ID in cache for fallback (valid for 24 hours)
            // Key: account_link_{account_id} -> account_id
            Cache::put(
                "account_link_{$connectAccount->connect_account_id}",
                $connectAccount->connect_account_id,
                now()->addHours(24)
            );

            Log::info('Creating Stripe AccountLink', [
                'user_id' => $connectAccount->user_id,
                'account_id_suffix' => substr($connectAccount->connect_account_id, -6),
                'base_return_url' => $baseReturnUrl,
            ]);

            $accountLink = \Stripe\AccountLink::create([
                'account' => $connectAccount->connect_account_id,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);

            // Update onboarding URL
            $connectAccount->update([
                'onboarding_url' => $accountLink->url,
            ]);

            Log::info('Stripe AccountLink created successfully', [
                'user_id' => $connectAccount->user_id,
                'account_id_suffix' => substr($connectAccount->connect_account_id, -6),
                'stripe_account_link_id' => $accountLink->id ?? null,
            ]);

            return $accountLink->url;
        } catch (\Exception $e) {
            Log::error('Stripe Onboarding Link Creation Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Create Checkout Session for one-time payment with hold period data.
     */
    public function createPaymentIntent(array $data): array
    {
        try {
            // Check if Stripe secret is configured
            $stripeSecret = config('services.stripe.secret');
            if (empty($stripeSecret)) {
                throw new \Exception('Stripe secret key is not configured. Please set STRIPE_SECRET in .env file.');
            }

            // Use provided return_url or fallback to config default
            $returnUrl = $data['return_url'] ?? config('services.stripe.payment_return_url');

            if (! $returnUrl) {
                throw new \Exception('return_url is required. Please provide return_url in request or set STRIPE_PAYMENT_RETURN_URL in .env file.');
            }

            // Prepare metadata for hold period
            $metadata = [
                'user_id' => (string) $data['user_id'],
            ];

            if (isset($data['hold_period_type'])) {
                $metadata['hold_period_type'] = $data['hold_period_type'];
                $metadata['hold_start_at'] = $data['hold_start_at'] ?? now()->toIso8601String();
                $metadata['hold_end_at'] = $data['hold_end_at'] ?? now()->addDays(30)->toIso8601String();
                $metadata['hold_days'] = (string) ($data['hold_days'] ?? 30);
                $metadata['hold_hours'] = (string) ($data['hold_hours'] ?? 0);
                $metadata['hold_minutes'] = (string) ($data['hold_minutes'] ?? 0);
            }

            // Add title if provided
            if (isset($data['title'])) {
                $metadata['title'] = $data['title'];
            }

            // Create Checkout Session with payment capture (funds go to platform account)
            // Note: For destination charges (direct to connected account), you would need to:
            // 1. Get user's connected account ID
            // 2. Use 'payment_intent_data.on_behalf_of' or 'payment_intent_data.transfer_data'
            // For now, using platform account (standard approach for hold periods)

            $checkoutSession = \Stripe\Checkout\Session::create([
                'payment_intent_data' => [
                    'metadata' => $metadata,
                    'capture_method' => 'automatic', // Capture immediately to platform account
                ],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($data['currency'] ?? 'usd'),
                        'product_data' => [
                            'name' => 'Payment',
                        ],
                        'unit_amount' => $data['amount'],
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $returnUrl.'?session_id={CHECKOUT_SESSION_ID}&status=success',
                'cancel_url' => $returnUrl.'?session_id={CHECKOUT_SESSION_ID}&status=canceled',
                'payment_method_types' => ['card'],
            ], [
                'expand' => ['payment_intent'],
            ]);

            // Get PaymentIntent created by Checkout Session
            $paymentIntentId = null;

            // Try to get from expanded payment_intent
            if (isset($checkoutSession->payment_intent)) {
                if (is_string($checkoutSession->payment_intent)) {
                    $paymentIntentId = $checkoutSession->payment_intent;
                } elseif (is_object($checkoutSession->payment_intent) && isset($checkoutSession->payment_intent->id)) {
                    $paymentIntentId = $checkoutSession->payment_intent->id;
                }
            }

            // If still not found, retrieve session again with expand
            if (! $paymentIntentId) {
                $retrievedSession = \Stripe\Checkout\Session::retrieve($checkoutSession->id, [
                    'expand' => ['payment_intent'],
                ]);

                if (isset($retrievedSession->payment_intent)) {
                    if (is_string($retrievedSession->payment_intent)) {
                        $paymentIntentId = $retrievedSession->payment_intent;
                    } elseif (is_object($retrievedSession->payment_intent)) {
                        $paymentIntentId = $retrievedSession->payment_intent->id;
                    }
                }
            }

            // If PaymentIntent not found, log warning but still return checkout_url
            // Webhook will handle PaymentIntent when payment succeeds
            if (! $paymentIntentId) {
                Log::warning('PaymentIntent not immediately available in checkout session', [
                    'checkout_session_id' => $checkoutSession->id,
                ]);
            }

            $response = [
                'checkout_url' => $checkoutSession->url,
                'checkout_session_id' => $checkoutSession->id,
            ];

            // Add payment_intent_id if available
            if ($paymentIntentId) {
                $response['payment_intent_id'] = $paymentIntentId;

                // Store hold period data in cache for webhook handler
                if (isset($data['hold_period_type'])) {
                    Cache::put(
                        "payment_intent_hold_{$paymentIntentId}",
                        [
                            'hold_period_type' => $data['hold_period_type'],
                            'hold_start_at' => $data['hold_start_at'] ?? null,
                            'hold_end_at' => $data['hold_end_at'] ?? null,
                            'hold_days' => $data['hold_days'] ?? null,
                            'hold_hours' => $data['hold_hours'] ?? 0,
                            'hold_minutes' => $data['hold_minutes'] ?? 0,
                            'user_id' => $data['user_id'],
                        ],
                        now()->addDays(7)
                    );
                }
            } else {
                // Store session data in cache for webhook handler if payment_intent not available
                if (isset($data['hold_period_type'])) {
                    Cache::put(
                        "checkout_session_hold_{$checkoutSession->id}",
                        [
                            'hold_period_type' => $data['hold_period_type'],
                            'hold_start_at' => $data['hold_start_at'] ?? null,
                            'hold_end_at' => $data['hold_end_at'] ?? null,
                            'hold_days' => $data['hold_days'] ?? null,
                            'hold_hours' => $data['hold_hours'] ?? 0,
                            'hold_minutes' => $data['hold_minutes'] ?? 0,
                            'user_id' => $data['user_id'],
                        ],
                        now()->addDays(7)
                    );
                }
            }

            // Add hold period info to response
            if (isset($data['hold_period_type'])) {
                $response['hold_period'] = [
                    'type' => $data['hold_period_type'],
                    'start_at' => $data['hold_start_at'] ?? now()->toIso8601String(),
                    'end_at' => $data['hold_end_at'] ?? now()->addDays(30)->toIso8601String(),
                    'days' => (int) ($data['hold_days'] ?? 30),
                    'hours' => (int) ($data['hold_hours'] ?? 0),
                    'minutes' => (int) ($data['hold_minutes'] ?? 0),
                ];
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('Stripe Checkout Session Creation Failed: '.$e->getMessage());
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
                throw new \Exception("Stripe Connect account not found for user {$hold->user_id}. Please add bank details first.");
            }

            if (! $connectAccount->payouts_enabled) {
                throw new \Exception("Stripe Connect account for user {$hold->user_id} does not have payouts enabled. Status: {$connectAccount->status}");
            }

            // Get currency from payment
            $payment = $hold->payment;
            $currency = $payment ? strtolower($payment->currency) : 'usd';

            // In test mode, we need to ensure the platform has sufficient balance
            // For production, make sure payments are captured to platform account first

            // Use remaining_amount if available, otherwise use amount
            $transferAmount = $hold->remaining_amount ?? $hold->amount;

            // Create transfer in Stripe (real API call - no simulation)
            $transfer = \Stripe\Transfer::create([
                'amount' => (int) round($transferAmount * 100), // Convert to cents
                'currency' => $currency,
                'destination' => $connectAccount->connect_account_id,
                'description' => "Transfer for hold #{$hold->id}",
                'metadata' => [
                    'hold_id' => $hold->id,
                    'user_id' => $hold->user_id,
                    'payment_id' => $payment ? $payment->id : null,
                ],
            ]);

            // Create transfer record
            $adminId = null;
            if (\Illuminate\Support\Facades\Auth::check()) {
                $user = \Illuminate\Support\Facades\Auth::user();
                $adminId = ($user && isset($user->role) && $user->role === 'admin') ? $user->id : null;
            }

            $transferRecord = Transfer::create([
                'hold_id' => $hold->id,
                'user_id' => $hold->user_id,
                'stripe_transfer_id' => $transfer->id,
                'stripe_connect_account_id' => $connectAccount->connect_account_id,
                'amount' => $transferAmount,
                'currency' => $currency,
                'status' => 'pending',
                'transfer_type' => $type,
                'admin_id' => $adminId,
                'stripe_data' => $transfer->toArray(),
            ]);

            // Calculate new remaining amount
            $currentRemaining = $hold->remaining_amount ?? $hold->amount;
            $newRemainingAmount = max(0, $currentRemaining - $transferAmount);

            // Update hold with remaining_amount and appropriate status
            $hold->update([
                'remaining_amount' => $newRemainingAmount,
                'status' => $newRemainingAmount <= 0 ? 'transferred' : 'partial_transferred',
                'transferred_at' => $newRemainingAmount <= 0 ? now() : $hold->transferred_at,
            ]);

            return $transferRecord;
        } catch (\Exception $e) {
            Log::error('Stripe Transfer Creation Failed: '.$e->getMessage(), [
                'hold_id' => $hold->id,
                'user_id' => $hold->user_id,
                'error' => $e->getMessage(),
            ]);
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
                    'hold_hours' => $paymentIntent->metadata->hold_hours ?? 0,
                    'hold_minutes' => $paymentIntent->metadata->hold_minutes ?? 0,
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to retrieve hold period data: '.$e->getMessage());
        }

        return null;
    }
}
