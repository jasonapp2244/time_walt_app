<?php

namespace App\Services;

use App\Jobs\SendPaymentFailedNotification;
use App\Jobs\SendPaymentSuccessNotification;
use App\Jobs\SendTransferCompletedNotification;
use App\Jobs\SendTransferFailedNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(
        protected PaymentHoldService $paymentHoldService,
        protected StripeService $stripeService
    ) {}

    /**
     * Handle checkout.session.completed event (when payment succeeds via Checkout Session).
     */
    public function handleCheckoutSessionCompleted(array $eventData): void
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        if (! isset($eventData['data']['object'])) {
            Log::warning('Invalid webhook event structure: missing data.object', $eventData);

            return;
        }

        $session = $eventData['data']['object'];
        $sessionId = $session['id'] ?? null;
        $paymentIntentId = $session['payment_intent'] ?? null;

        if (! $paymentIntentId) {
            Log::warning('PaymentIntent ID missing from checkout session', [
                'session_id' => $sessionId,
            ]);

            return;
        }

        // Get user_id and hold period data from PaymentIntent metadata
        $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        $userId = $paymentIntent->metadata->user_id ?? null;

        if (! $userId) {
            Log::warning('User ID missing from payment intent metadata', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        // Get amount from session
        $amount = ($session['amount_total'] ?? 0) / 100;
        $currency = $session['currency'] ?? 'usd';

        // Check if payment already exists
        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntentId))->first();

        // Extract card details from latest charge
        $charge = $paymentIntent->latest_charge;
        if (is_string($charge)) {
            $charge = \Stripe\Charge::retrieve($charge);
        }
        $cardDetails = $charge?->payment_method_details?->card ?? null;
        $cardData = [
            'card_brand' => $cardDetails->brand ?? null,
            'card_last4' => $cardDetails->last4 ?? null,
            'card_exp_month' => $cardDetails->exp_month ?? null,
            'card_exp_year' => $cardDetails->exp_year ?? null,
            'card_funding' => $cardDetails->funding ?? null,
            'card_country' => $cardDetails->country ?? null,
            'payment_method_type' => $cardDetails?->wallet?->type ?? 'card',
        ];

        if (! $payment) {
            // Create payment record
            $payment = Payment::create(array_merge([
                'user_id' => $userId,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'succeeded',
                'paid_at' => now(),
                'stripe_data' => $paymentIntent->toArray(),
            ], $cardData));
        } else {
            // Update existing payment
            $payment->update(array_merge([
                'status' => 'succeeded',
                'paid_at' => now(),
                'stripe_data' => $paymentIntent->toArray(),
            ], $cardData));
        }

        // Get hold period data from PaymentIntent metadata or cache
        $holdPeriodData = null;
        if (isset($paymentIntent->metadata->hold_period_type)) {
            $holdPeriodData = [
                'hold_period_type' => $paymentIntent->metadata->hold_period_type,
                'hold_start_at' => $paymentIntent->metadata->hold_start_at ?? null,
                'hold_end_at' => $paymentIntent->metadata->hold_end_at ?? null,
                'hold_days' => $paymentIntent->metadata->hold_days ?? null,
                'hold_hours' => $paymentIntent->metadata->hold_hours ?? 0,
                'hold_minutes' => $paymentIntent->metadata->hold_minutes ?? 0,
                'title' => $paymentIntent->metadata->title ?? null,
            ];
        } else {
            // Try to get from cache (fallback if metadata not set)
            $cachedHoldData = Cache::get("payment_intent_hold_{$paymentIntentId}");
            if ($cachedHoldData) {
                $holdPeriodData = [
                    'hold_period_type' => $cachedHoldData['hold_period_type'] ?? null,
                    'hold_start_at' => $cachedHoldData['hold_start_at'] ?? null,
                    'hold_end_at' => $cachedHoldData['hold_end_at'] ?? null,
                    'hold_days' => $cachedHoldData['hold_days'] ?? null,
                    'hold_hours' => $cachedHoldData['hold_hours'] ?? 0,
                    'hold_minutes' => $cachedHoldData['hold_minutes'] ?? 0,
                    'title' => $cachedHoldData['title'] ?? null,
                ];
            }
        }

        // Create payment hold if hold period data exists and hold doesn't exist
        if ($holdPeriodData && ! PaymentHold::where('payment_id', $payment->id)->exists()) {
            $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);
        }

        // Send email notification (check both transaction_alert and email_alert)
        $userSettings = UserNotificationSetting::where('user_id', $userId)->first();
        $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

        if ($shouldSendEmail) {
            SendPaymentSuccessNotification::dispatch($payment);
        }
    }

    /**
     * Handle payment_intent.succeeded event.
     */
    public function handlePaymentIntentSucceeded(array $eventData): void
    {
        $paymentIntent = $eventData['data']['object'];
        $paymentIntentId = $paymentIntent['id'];

        // Find payment record
        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntentId))->first();

        // Extract card details from payment intent charges (array format)
        $chargeData = $paymentIntent['latest_charge'] ?? ($paymentIntent['charges']['data'][0] ?? null);
        if (is_string($chargeData)) {
            $chargeData = \Stripe\Charge::retrieve($chargeData)->toArray();
        }
        $cardArr = $chargeData['payment_method_details']['card'] ?? [];
        $cardData = [
            'card_brand' => $cardArr['brand'] ?? null,
            'card_last4' => $cardArr['last4'] ?? null,
            'card_exp_month' => $cardArr['exp_month'] ?? null,
            'card_exp_year' => $cardArr['exp_year'] ?? null,
            'card_funding' => $cardArr['funding'] ?? null,
            'card_country' => $cardArr['country'] ?? null,
            'payment_method_type' => $cardArr['wallet']['type'] ?? 'card',
        ];

        if (! $payment) {
            // Payment not found — create it to prevent lost payments
            $userId = $paymentIntent['metadata']['user_id'] ?? null;

            if (! $userId) {
                Log::error('Payment not found and user_id missing from metadata for payment intent: '.substr($paymentIntentId, -6));

                return;
            }

            $amount = ($paymentIntent['amount'] ?? 0) / 100;
            $currency = $paymentIntent['currency'] ?? 'usd';

            $payment = Payment::create(array_merge([
                'user_id' => $userId,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'succeeded',
                'paid_at' => now(),
                'stripe_data' => $paymentIntent,
            ], $cardData));

            Log::info('Payment record created from webhook (was missing)', [
                'payment_id' => $payment->id,
                'payment_intent_suffix' => substr($paymentIntentId, -6),
            ]);
        } else {
            // Update existing payment status
            $payment->update(array_merge([
                'status' => 'succeeded',
                'paid_at' => now(),
                'stripe_data' => $paymentIntent,
            ], $cardData));
        }

        // Get hold period data from cache or metadata
        $holdPeriodData = $this->stripeService->getHoldPeriodData($paymentIntentId);

        // Create payment hold if not exists and hold period data available
        if ($holdPeriodData && ! PaymentHold::where('payment_id', $payment->id)->exists()) {
            $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);
        }

        // Send email notification (check both transaction_alert and email_alert)
        $userSettings = UserNotificationSetting::where('user_id', $payment->user_id)->first();
        $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

        if ($shouldSendEmail) {
            SendPaymentSuccessNotification::dispatch($payment);
        }
    }

    /**
     * Handle payment_intent.payment_failed event.
     */
    public function handlePaymentIntentFailed(array $eventData): void
    {
        $paymentIntent = $eventData['data']['object'];
        $paymentIntentId = $paymentIntent['id'];
        $failureReason = $paymentIntent['last_payment_error']['message'] ?? 'Payment failed';

        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntentId))->first();

        if ($payment) {
            $payment->update([
                'status' => 'failed',
                'failure_reason' => $failureReason,
                'stripe_data' => $paymentIntent,
            ]);

            // Send email notification
            $userSettings = UserNotificationSetting::where('user_id', $payment->user_id)->first();
            if (! $userSettings || $userSettings->email_alert) {
                SendPaymentFailedNotification::dispatch($payment, $failureReason);
            }
        }
    }

    /**
     * Handle account.updated event.
     */
    public function handleAccountUpdated(array $eventData): void
    {
        $account = $eventData['data']['object'];
        $accountId = $account['id'];

        $connectAccount = StripeConnectAccount::where('connect_account_id_index', StripeConnectAccount::blindIndex($accountId))->first();

        if ($connectAccount) {
            $status = 'pending';
            if ($account['charges_enabled'] && $account['payouts_enabled']) {
                $status = 'verified';
            } elseif ($account['restrictions']['charges'] ?? false) {
                $status = 'restricted';
            }

            $connectAccount->update([
                'status' => $status,
                'payouts_enabled' => $account['payouts_enabled'] ?? false,
                'stripe_data' => $account,
                'verified_at' => ($status === 'verified' && ! $connectAccount->verified_at) ? now() : $connectAccount->verified_at,
            ]);
        }
    }

    /**
     * Handle transfer.created event.
     * NOTE: NOT USED - Transfers are handled via cronjob (verify:pending-transfers) only.
     * This method is kept for potential future use but is not called from webhook handler.
     */
    public function handleTransferCreated(array $eventData): void
    {
        $transfer = $eventData['data']['object'];
        $transferId = $transfer['id'];

        $transferRecord = Transfer::where('stripe_transfer_id_index', Transfer::blindIndex($transferId))->first();

        if ($transferRecord) {
            $transferRecord->update([
                'status' => 'completed',
                'transferred_at' => now(),
                'stripe_data' => $transfer,
            ]);

            // Update payment hold with remaining_amount calculation
            $hold = $transferRecord->hold;
            if ($hold) {
                // Calculate remaining amount
                $currentRemaining = $hold->remaining_amount ?? $hold->amount;
                $newRemainingAmount = max(0, $currentRemaining - $transferRecord->amount);

                // Update hold status and remaining_amount
                $hold->update([
                    'remaining_amount' => $newRemainingAmount,
                    'status' => $newRemainingAmount <= 0 ? 'transferred' : 'partial_transferred',
                    'transferred_at' => $newRemainingAmount <= 0 ? now() : $hold->transferred_at,
                ]);
            }

            // Check for other recently completed transfers for the same user (within last 2 minutes)
            // This batches transfers from the same withdrawal request
            $recentTransfers = Transfer::where('user_id', $transferRecord->user_id)
                ->where('status', 'completed')
                ->where('transferred_at', '>=', now()->subMinutes(2))
                ->where('transferred_at', '<=', now())
                ->with(['hold.payment', 'user'])
                ->orderBy('transferred_at', 'asc')
                ->get();

            // Send email notification (check both transaction_alert and email_alert)
            $userSettings = UserNotificationSetting::where('user_id', $transferRecord->user_id)->first();
            $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

            if ($shouldSendEmail) {
                // If multiple transfers completed recently, send summary email
                if ($recentTransfers->count() > 1) {
                    $totalAmount = $recentTransfers->sum('amount');
                    \App\Jobs\SendTransferCompletedSummaryNotification::dispatch(
                        $transferRecord->user,
                        $recentTransfers,
                        $totalAmount
                    );
                } else {
                    // Single transfer, send individual email
                    SendTransferCompletedNotification::dispatch($transferRecord);
                }
            }
        }
    }

    /**
     * Handle transfer.failed event.
     * NOTE: NOT USED - Transfers are handled via cronjob (verify:pending-transfers) only.
     * This method is kept for potential future use but is not called from webhook handler.
     */
    public function handleTransferFailed(array $eventData): void
    {
        $transfer = $eventData['data']['object'];
        $transferId = $transfer['id'];
        $failureReason = $transfer['failure_message'] ?? 'Transfer failed';

        $transferRecord = Transfer::where('stripe_transfer_id_index', Transfer::blindIndex($transferId))->first();

        if ($transferRecord) {
            $transferRecord->update([
                'status' => 'failed',
                'failure_reason' => $failureReason,
                'stripe_data' => $transfer,
            ]);

            // Send email notification
            $userSettings = UserNotificationSetting::where('user_id', $transferRecord->user_id)->first();
            if (! $userSettings || $userSettings->email_alert) {
                SendTransferFailedNotification::dispatch($transferRecord, $failureReason);
            }
        }
    }

    /**
     * Handle payment_intent.canceled event (when user cancels payment).
     */
    public function handlePaymentIntentCanceled(array $eventData): void
    {
        $paymentIntent = $eventData['data']['object'];
        $paymentIntentId = $paymentIntent['id'];
        $cancelReason = $paymentIntent['cancellation_reason'] ?? 'Payment canceled by user';

        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntentId))->first();

        if (! $payment) {
            Log::warning('Payment not found for payment intent suffix: '.substr($paymentIntentId, -6));

            return;
        }

        $payment->update([
            'status' => 'canceled',
            'failure_reason' => $cancelReason,
            'stripe_data' => $paymentIntent,
        ]);

        // If payment hold exists, update it
        $hold = PaymentHold::where('payment_id', $payment->id)->first();

        if ($hold) {
            $hold->update([
                'status' => 'canceled',
            ]);
        }

        // Send email notification
        $userSettings = UserNotificationSetting::where('user_id', $payment->user_id)->first();
        if (! $userSettings || $userSettings->email_alert) {
            SendPaymentFailedNotification::dispatch($payment, $cancelReason);
        }
    }

    /**
     * Handle transfer.canceled event (when transfer is canceled mid-process).
     * NOTE: NOT USED - Transfers are handled via cronjob (verify:pending-transfers) only.
     * This method is kept for potential future use but is not called from webhook handler.
     */
    public function handleTransferCanceled(array $eventData): void
    {
        $transfer = $eventData['data']['object'];
        $transferId = $transfer['id'];
        $cancelReason = $transfer['failure_message'] ?? 'Transfer canceled';

        $transferRecord = Transfer::where('stripe_transfer_id_index', Transfer::blindIndex($transferId))->first();

        if ($transferRecord) {
            $transferRecord->update([
                'status' => 'canceled',
                'failure_reason' => $cancelReason,
                'stripe_data' => $transfer,
            ]);

            // Update payment hold status back to ready_for_transfer
            if ($transferRecord->hold) {
                $transferRecord->hold->update([
                    'status' => 'ready_for_transfer',
                ]);
            }

            // Send email notification
            $userSettings = UserNotificationSetting::where('user_id', $transferRecord->user_id)->first();
            if (! $userSettings || $userSettings->email_alert) {
                SendTransferFailedNotification::dispatch($transferRecord, $cancelReason);
            }
        }
    }
}
