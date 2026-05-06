<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\StripeCustomer;
use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentSheetService
{
    public function __construct(
        protected PaymentHoldService $paymentHoldService
    ) {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Get existing or create new Stripe Customer for user.
     */
    public function getOrCreateCustomer(User $user): StripeCustomer
    {
        $existing = StripeCustomer::where('user_id', $user->id)->first();

        if ($existing) {
            return $existing;
        }

        $customer = \Stripe\Customer::create([
            'email' => $user->email,
            'name' => $user->full_name,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        return StripeCustomer::create([
            'user_id' => $user->id,
            'stripe_customer_id' => $customer->id,
            'stripe_data' => $customer->toArray(),
        ]);
    }

    /**
     * Create Ephemeral Key for customer (required by Payment Sheet SDK).
     */
    public function createEphemeralKey(string $customerId): string
    {
        $ephemeralKey = \Stripe\EphemeralKey::create(
            ['customer' => $customerId],
            ['stripe_version' => '2024-06-20']
        );

        return $ephemeralKey->secret;
    }

    /**
     * Create PaymentIntent + Customer + EphemeralKey for Payment Sheet.
     * Returns the 4 values Flutter needs.
     */
    public function createPaymentIntent(User $user, array $data): array
    {
        $stripeCustomer = $this->getOrCreateCustomer($user);

        $ephemeralKey = $this->createEphemeralKey($stripeCustomer->stripe_customer_id);

        $amountInCents = (int) round($data['amount'] * 100);

        $metadata = [
            'user_id' => (string) $user->id,
        ];

        if (isset($data['hold_period_type'])) {
            $metadata['hold_period_type'] = $data['hold_period_type'];
            $metadata['hold_start_at'] = $data['hold_start_at'] ?? now()->toIso8601String();
            $metadata['hold_end_at'] = $data['hold_end_at'] ?? now()->addDays(30)->toIso8601String();
            $metadata['hold_days'] = (string) ($data['hold_days'] ?? 30);
        }

        if (isset($data['title'])) {
            $metadata['title'] = $data['title'];
        }

        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => $amountInCents,
            'currency' => strtolower($data['currency'] ?? 'usd'),
            'customer' => $stripeCustomer->stripe_customer_id,
            'setup_future_usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $metadata,
        ]);

        Log::info('PaymentIntent created for Payment Sheet', [
            'payment_intent_id' => $paymentIntent->id,
            'user_id' => $user->id,
            'amount' => $data['amount'],
        ]);

        return [
            'payment_intent_id' => $paymentIntent->id,
            'client_secret' => $paymentIntent->client_secret,
            'customer_id' => $stripeCustomer->stripe_customer_id,
            'ephemeral_key' => $ephemeralKey,
            'publishable_key' => config('services.stripe.publishable_key'),
            'amount' => (float) $data['amount'],
            'currency' => strtolower($data['currency'] ?? 'usd'),
        ];
    }

    /**
     * Confirm payment after Payment Sheet succeeds.
     * Creates Payment + PaymentHold records.
     */
    public function confirmPayment(string $paymentIntentId, User $user): array
    {
        $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId, [
            'expand' => ['latest_charge.payment_method_details'],
        ]);

        if ($paymentIntent->status !== 'succeeded') {
            throw new \Exception('Payment not completed yet. Status: ' . $paymentIntent->status);
        }

        $metadataUserId = $paymentIntent->metadata->user_id ?? null;
        if ((int) $metadataUserId !== $user->id) {
            throw new \Exception('Payment does not belong to this user.');
        }

        // Check duplicate using firstOrCreate to prevent race conditions
        $blindIndex = Payment::blindIndex($paymentIntentId);
        $existingPayment = Payment::where('payment_intent_id_index', $blindIndex)->first();
        if ($existingPayment) {
            return [
                'payment' => $this->formatPayment($existingPayment),
                'hold' => $existingPayment->hold ? $this->formatHold($existingPayment->hold) : null,
                'already_recorded' => true,
            ];
        }

        // Extract card details from latest charge (null-safe)
        $charge = $paymentIntent->latest_charge;
        $cardDetails = $charge?->payment_method_details?->card ?? null;
        $walletType = $cardDetails?->wallet?->type ?? null;

        $amount = $paymentIntent->amount / 100;
        $currency = $paymentIntent->currency;

        // Wrap Payment + Hold creation in a transaction for atomicity
        $result = DB::transaction(function () use ($user, $paymentIntentId, $blindIndex, $amount, $currency, $paymentIntent, $cardDetails, $walletType) {
            // Use lockForUpdate check to prevent race condition between concurrent requests
            $existingPayment = Payment::where('payment_intent_id_index', $blindIndex)->lockForUpdate()->first();
            if ($existingPayment) {
                return [
                    'payment' => $existingPayment,
                    'hold' => $existingPayment->hold,
                    'already_recorded' => true,
                ];
            }

            $payment = Payment::create([
                'user_id' => $user->id,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'succeeded',
                'paid_at' => now(),
                'stripe_data' => $paymentIntent->toArray(),
                'card_brand' => $cardDetails->brand ?? null,
                'card_last4' => $cardDetails->last4 ?? null,
                'card_exp_month' => $cardDetails->exp_month ?? null,
                'card_exp_year' => $cardDetails->exp_year ?? null,
                'card_funding' => $cardDetails->funding ?? null,
                'card_country' => $cardDetails->country ?? null,
                'payment_method_type' => $walletType ?? 'card',
            ]);

            Log::info('Payment record created via Payment Sheet', [
                'payment_id' => $payment->id,
                'amount' => $amount,
                'card_brand' => $payment->card_brand,
                'payment_method_type' => $payment->payment_method_type,
            ]);

            // Create payment hold if hold period data exists in metadata
            $hold = null;
            if (isset($paymentIntent->metadata->hold_period_type)) {
                $holdPeriodData = [
                    'hold_period_type' => $paymentIntent->metadata->hold_period_type,
                    'hold_start_at' => $paymentIntent->metadata->hold_start_at ?? null,
                    'hold_end_at' => $paymentIntent->metadata->hold_end_at ?? null,
                    'hold_days' => $paymentIntent->metadata->hold_days ?? null,
                    'title' => $paymentIntent->metadata->title ?? null,
                ];

                $hold = $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);

                Log::info('PaymentHold created via Payment Sheet', [
                    'hold_id' => $hold->id,
                    'title' => $hold->title ?? 'No title',
                ]);
            }

            return [
                'payment' => $payment,
                'hold' => $hold,
                'already_recorded' => false,
            ];
        });

        // If this was a duplicate caught inside the transaction
        if ($result['already_recorded']) {
            return [
                'payment' => $this->formatPayment($result['payment']),
                'hold' => $result['hold'] ? $this->formatHold($result['hold']) : null,
                'already_recorded' => true,
            ];
        }

        // Send email notification (outside transaction to avoid holding locks)
        $this->dispatchNotification($result['payment']);

        return [
            'payment' => $this->formatPayment($result['payment']),
            'hold' => $result['hold'] ? $this->formatHold($result['hold']) : null,
        ];
    }

    /**
     * Dispatch payment success notification if user settings allow.
     */
    protected function dispatchNotification(Payment $payment): void
    {
        $userSettings = UserNotificationSetting::where('user_id', $payment->user_id)->first();

        if ($userSettings) {
            $transactionEnabled = ((int) $userSettings->transaction_alert) === 1;
            $emailEnabled = ((int) $userSettings->email_alert) === 1;
            $shouldSendEmail = $transactionEnabled && $emailEnabled;
        } else {
            $shouldSendEmail = true;
        }

        if ($shouldSendEmail) {
            \App\Jobs\SendPaymentSuccessNotification::dispatch($payment);
            Log::info('Payment success email dispatched', ['payment_id' => $payment->id]);
        } else {
            Log::info('Payment success email blocked by user settings', ['payment_id' => $payment->id]);
        }
    }

    protected function formatPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_intent_id' => $payment->payment_intent_id,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at->toIso8601String(),
            'card_brand' => $payment->card_brand,
            'card_last4' => $payment->card_last4,
            'card_exp_month' => $payment->card_exp_month,
            'card_exp_year' => $payment->card_exp_year,
            'card_funding' => $payment->card_funding,
            'card_country' => $payment->card_country,
            'payment_method_type' => $payment->payment_method_type,
        ];
    }

    protected function formatHold($hold): array
    {
        return [
            'id' => $hold->id,
            'amount' => (float) $hold->amount,
            'status' => $hold->status,
            'hold_start_at' => $hold->hold_start_at->toIso8601String(),
            'hold_end_at' => $hold->hold_end_at->toIso8601String(),
            'hold_days' => $hold->hold_days,
            'title' => $hold->title,
        ];
    }
}
