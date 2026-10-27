<?php

namespace App\Services;

use App\Jobs\SendPaymentFailedNotification;
use App\Jobs\SendPaymentSuccessNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(
        protected PaymentHoldService $paymentHoldService,
        protected StripeService $stripeService,
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * customer.subscription.created / updated / deleted: mirror the status.
     */
    public function handleSubscriptionChanged(array $eventData): void
    {
        // Stripe does not deliver events in order, so a late "updated: active"
        // could undo a "deleted". Read the current state instead of trusting
        // the event's copy; if Stripe is unreachable the 500 makes it retry.
        $this->subscriptionService->syncFromStripe($eventData['data']['object']['id']);
    }

    /**
     * invoice.paid / invoice.payment_failed / invoice.payment_action_required.
     */
    public function handleSubscriptionInvoice(array $eventData): void
    {
        $this->subscriptionService->handleInvoiceEvent($eventData['data']['object'], $eventData['type']);
    }

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
        if ($holdPeriodData) {
            $this->createHoldOnce($payment, $holdPeriodData);
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

        // Every deposit PaymentIntent carries user_id metadata. One without it and
        // without a Payment row is not a deposit - typically a subscription
        // invoice, which the invoice.* events handle.
        if (! $payment && empty($paymentIntent['metadata']['user_id'])) {
            Log::info('Ignoring payment_intent.succeeded that is not a deposit', [
                'payment_intent_suffix' => substr($paymentIntentId, -6),
            ]);

            return;
        }

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

        // The app's /confirm-payment usually records the deposit and sends the
        // receipt first; only send one here if this event is what settled it.
        $alreadySucceeded = $payment && $payment->status === 'succeeded';

        if (! $payment) {
            // Payment not found — create it to prevent lost payments
            $userId = $paymentIntent['metadata']['user_id'];

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
        if ($holdPeriodData) {
            $this->createHoldOnce($payment, $holdPeriodData);
        }

        if ($alreadySucceeded) {
            return;
        }

        // Send email notification (check both transaction_alert and email_alert)
        $userSettings = UserNotificationSetting::where('user_id', $payment->user_id)->first();
        $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

        if ($shouldSendEmail) {
            SendPaymentSuccessNotification::dispatch($payment);
        }
    }

    /**
     * Create the payment's hold unless it has one. The payment row is locked
     * so two deliveries of the same event arriving together cannot both
     * create a hold (and so double the user's balance).
     */
    private function createHoldOnce(Payment $payment, array $holdPeriodData): void
    {
        DB::transaction(function () use ($payment, $holdPeriodData) {
            Payment::whereKey($payment->id)->lockForUpdate()->first(['id']);

            if (! PaymentHold::where('payment_id', $payment->id)->exists()) {
                $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);
            }
        });
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
}
