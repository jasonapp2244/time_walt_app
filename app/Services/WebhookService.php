<?php

namespace App\Services;

use App\Jobs\SendPaymentFailedNotification;
use App\Jobs\SendPaymentSuccessNotification;
use App\Jobs\SendTransferCompletedNotification;
use App\Jobs\SendTransferFailedNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\StripeWebhookEvent;
use App\Models\Transfer;
use App\Models\UserNotificationSetting;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function __construct(
        protected PaymentHoldService $paymentHoldService,
        protected StripeService $stripeService
    ) {
    }

    /**
     * Handle payment_intent.succeeded event.
     */
    public function handlePaymentIntentSucceeded(array $eventData): void
    {
        $paymentIntent = $eventData['data']['object'];
        $paymentIntentId = $paymentIntent['id'];

        // Find or create payment record
        $payment = Payment::where('payment_intent_id', $paymentIntentId)->first();

        if ($payment) {
            // Update payment status
            $payment->update([
                'status' => 'succeeded',
                'paid_at' => now(),
                'stripe_data' => $paymentIntent,
            ]);

            // Get hold period data
            $holdPeriodData = $this->stripeService->getHoldPeriodData($paymentIntentId);

            // Create payment hold
            if ($holdPeriodData) {
                $hold = $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);
            } else {
                // Default 30 days if no hold period data
                $hold = $this->paymentHoldService->createFromPayment($payment);
            }

            // Send email notification (check user preferences)
            $userSettings = UserNotificationSetting::where('user_id', $payment->user_id)->first();
            if (! $userSettings || $userSettings->email_alert) {
                SendPaymentSuccessNotification::dispatch($payment);
            }
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

        $payment = Payment::where('payment_intent_id', $paymentIntentId)->first();

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

        $connectAccount = StripeConnectAccount::where('connect_account_id', $accountId)->first();

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
     */
    public function handleTransferCreated(array $eventData): void
    {
        $transfer = $eventData['data']['object'];
        $transferId = $transfer['id'];

        $transferRecord = Transfer::where('stripe_transfer_id', $transferId)->first();

        if ($transferRecord) {
            $transferRecord->update([
                'status' => 'completed',
                'transferred_at' => now(),
                'stripe_data' => $transfer,
            ]);

            // Update payment hold status
            $transferRecord->hold->update([
                'status' => 'transferred',
                'transferred_at' => now(),
            ]);

            // Send email notification
            $userSettings = UserNotificationSetting::where('user_id', $transferRecord->user_id)->first();
            if (! $userSettings || $userSettings->email_alert) {
                SendTransferCompletedNotification::dispatch($transferRecord);
            }
        }
    }

    /**
     * Handle transfer.failed event.
     */
    public function handleTransferFailed(array $eventData): void
    {
        $transfer = $eventData['data']['object'];
        $transferId = $transfer['id'];
        $failureReason = $transfer['failure_message'] ?? 'Transfer failed';

        $transferRecord = Transfer::where('stripe_transfer_id', $transferId)->first();

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

        $payment = Payment::where('payment_intent_id', $paymentIntentId)->first();

        if ($payment) {
            $payment->update([
                'status' => 'canceled',
                'failure_reason' => $cancelReason,
                'stripe_data' => $paymentIntent,
            ]);

            // If payment hold exists, update it
            $hold = PaymentHold::whereHas('payment', function ($query) use ($paymentIntentId) {
                $query->where('payment_intent_id', $paymentIntentId);
            })->first();

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

    /**
     * Handle transfer.canceled event (when transfer is canceled mid-process).
     */
    public function handleTransferCanceled(array $eventData): void
    {
        $transfer = $eventData['data']['object'];
        $transferId = $transfer['id'];
        $cancelReason = $transfer['failure_message'] ?? 'Transfer canceled';

        $transferRecord = Transfer::where('stripe_transfer_id', $transferId)->first();

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
