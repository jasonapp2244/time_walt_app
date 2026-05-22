<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\CreateConnectAccountRequest;
use App\Http\Requests\Stripe\CreatePaymentIntentRequest;
use App\Http\Requests\Stripe\GetOnboardingLinkRequest;
use App\Models\Payment;
use App\Models\StripeConnectAccount;
use App\Models\StripeWebhookEvent;
use App\Services\PaymentHoldService;
use App\Services\StripeService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    public function __construct(
        protected StripeService $stripeService,
        protected WebhookService $webhookService,
        protected PaymentHoldService $paymentHoldService
    ) {}

    /**
     * Create Stripe Connect Express account for user.
     */
    public function createConnectAccount(CreateConnectAccountRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $existingAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if ($existingAccount) {
                // Always re-check Stripe for the latest verification status
                // so the response reflects reality even if the callback never fired.
                if ($existingAccount->status !== 'verified') {
                    try {
                        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                        $stripeAccount = \Stripe\Account::retrieve($existingAccount->connect_account_id);

                        if ($stripeAccount->details_submitted) {
                            $existingAccount->update([
                                'status' => 'verified',
                                'payouts_enabled' => $stripeAccount->payouts_enabled ?? false,
                                'verified_at' => now(),
                                'stripe_data' => $stripeAccount->toArray(),
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Could not refresh Connect account status from Stripe: '.$e->getMessage());
                    }
                }

                $isVerified = $existingAccount->status === 'verified';

                return response()->json([
                    'success' => true,
                    'message' => $isVerified
                        ? 'Stripe Connect account verified.'
                        : 'Stripe Connect account already exists. Please complete onboarding.',
                    'data' => [
                        'connect_account_id' => $existingAccount->connect_account_id,
                        'status' => $existingAccount->status,
                        'onboarding_url' => $isVerified ? null : $existingAccount->onboarding_url,
                        'verified' => $isVerified,
                    ],
                ]);
            }

            $account = $this->stripeService->createConnectAccount($user);

            return response()->json([
                'success' => true,
                'message' => 'Stripe Connect account created successfully.',
                'data' => $account,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Connect Account Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create Stripe Connect account.',
            ], 500);
        }
    }

    /**
     * Get Stripe onboarding link.
     */
    public function getOnboardingLink(GetOnboardingLinkRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if (! $connectAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe Connect account not found.',
                ], 404);
            }

            $onboardingUrl = $this->stripeService->getOnboardingLink($connectAccount);

            return response()->json([
                'success' => true,
                'data' => [
                    'onboarding_url' => $onboardingUrl,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Onboarding Link Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate onboarding link.',
            ], 500);
        }
    }

    /**
     * Handle Stripe Connect OAuth callback.
     */
    public function handleConnectCallback(Request $request): JsonResponse
    {
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $accountId = $request->query('account');

            if (! $accountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account ID missing from Stripe callback.',
                ], 400);
            }

            $connectAccount = StripeConnectAccount::where('connect_account_id_index', StripeConnectAccount::blindIndex($accountId))->first();

            if (! $connectAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe Connect account not found.',
                ], 404);
            }

            $account = \Stripe\Account::retrieve($accountId);

            $connectAccount->update([
                'status' => $account->details_submitted ? 'verified' : 'pending',
                'payouts_enabled' => $account->payouts_enabled ?? false,
                'verified_at' => $account->details_submitted ? now() : null,
                'stripe_data' => $account->toArray(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stripe Connect account verified.',
            ]);
        } catch (\Exception $e) {
            Log::error('Connect Callback Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Stripe callback failed.',
            ], 500);
        }
    }

    /**
     * Create PaymentIntent.
     */
    public function createPaymentIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
        try {
            $amountInCents = (int) round($request->amount * 100);

            // Prepare hold period data if provided
            $holdPeriodData = [];
            if ($request->hold_period_type) {
                $holdPeriodData = [
                    'hold_period_type' => $request->hold_period_type,
                    'hold_start_at' => $request->hold_start_at,
                    'hold_end_at' => $request->hold_end_at,
                    // 'hold_days' => $request->hold_days,
                    'hold_hours' => $request->hold_hours ?? 0,
                    'hold_minutes' => $request->hold_minutes ?? 0,
                ];
            }

            // Add title if provided
            if ($request->title) {
                $holdPeriodData['title'] = $request->title;
            }

            // Create Checkout Session (NO webhook - use verify-payment endpoint after payment)
            $checkoutSession = $this->stripeService->createPaymentIntent([
                'user_id' => $request->user()->id,
                'amount' => $amountInCents,
                'currency' => $request->currency,
                'return_url' => $request->return_url,
                ...$holdPeriodData,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Checkout session created. After payment, call verify-payment endpoint with session_id.',
                'data' => $checkoutSession,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Payment Intent Failed: '.$e->getMessage());

            $errorMessage = 'Failed to create payment intent.';

            if (config('app.debug')) {
                $errorMessage .= ' Error: '.$e->getMessage();
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
            ], 500);
        }
    }

    /**
     * Verify payment and create database records (NO WEBHOOK NEEDED).
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'session_id' => 'required|string',
            ]);

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $sessionId = $request->input('session_id');

            Log::info('=== VERIFY PAYMENT CALLED ===', [
                'session_id' => $sessionId,
                'user_id' => $request->user()->id,
            ]);

            // Retrieve checkout session from Stripe
            $session = \Stripe\Checkout\Session::retrieve([
                'id' => $sessionId,
                'expand' => ['payment_intent'],
            ]);

            $paymentIntentId = $session->payment_intent->id ?? null;

            if (! $paymentIntentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found in session.',
                ], 404);
            }

            // Get payment intent details
            $paymentIntent = is_string($session->payment_intent)
                ? \Stripe\PaymentIntent::retrieve($paymentIntentId)
                : $session->payment_intent;

            $userId = $paymentIntent->metadata->user_id ?? null;

            // Verify user matches
            if ($userId != $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment does not belong to this user.',
                ], 403);
            }

            // Get amount and currency
            $amount = ($session->amount_total ?? 0) / 100;
            $currency = $session->currency ?? 'usd';

            // Check payment status
            if ($paymentIntent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed yet.',
                    'data' => [
                        'status' => $paymentIntent->status,
                    ],
                ], 400);
            }

            // ✅ PAYMENT SUCCEEDED - Create database records
            $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntentId))->first();

            if ($payment) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment already recorded.',
                    'data' => [
                        'payment_id' => $payment->id,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                    ],
                ]);
            }

            // Extract card details from latest charge
            $charge = $paymentIntent->latest_charge;
            if (is_string($charge)) {
                $charge = \Stripe\Charge::retrieve($charge);
            }
            $cardDetails = $charge?->payment_method_details?->card ?? null;

            // Create payment record
            $payment = Payment::create([
                'user_id' => $userId,
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
                'payment_method_type' => $cardDetails?->wallet?->type ?? 'card',
            ]);

            Log::info('✅ Payment record created via verify-payment', [
                'payment_id' => $payment->id,
                'amount' => $amount,
            ]);

            // Create payment hold if hold period data exists
            $hold = null;
            if (isset($paymentIntent->metadata->hold_period_type)) {
                $holdPeriodData = [
                    'hold_period_type' => $paymentIntent->metadata->hold_period_type,
                    'hold_start_at' => $paymentIntent->metadata->hold_start_at ?? null,
                    'hold_end_at' => $paymentIntent->metadata->hold_end_at ?? null,
                    'hold_days' => $paymentIntent->metadata->hold_days ?? null,
                    'hold_hours' => $paymentIntent->metadata->hold_hours ?? null,
                    'hold_minutes' => $paymentIntent->metadata->hold_minutes ?? null,
                    'title' => $paymentIntent->metadata->title ?? null,
                ];

                $hold = $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);

                Log::info('✅ PaymentHold record created via verify-payment', [
                    'hold_id' => $hold->id,
                    'title' => $hold->title ?? 'No title',
                ]);
            }

            // Send email notification to user and admin (check both transaction_alert and email_alert)
            $userSettings = \App\Models\UserNotificationSetting::where('user_id', $payment->user_id)->first();

            Log::info('📧 Checking email notification settings', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'settings_found' => $userSettings ? 'YES' : 'NO',
                'transaction_alert_raw' => $userSettings ? $userSettings->transaction_alert : 'NOT SET',
                'email_alert_raw' => $userSettings ? $userSettings->email_alert : 'NOT SET',
                'transaction_alert_int' => $userSettings ? (int) $userSettings->transaction_alert : 'NOT SET',
                'email_alert_int' => $userSettings ? (int) $userSettings->email_alert : 'NOT SET',
            ]);

            // Both must be 1 to send email (if settings exist)
            // Use strict comparison with integer cast
            if ($userSettings) {
                $transactionEnabled = ((int) $userSettings->transaction_alert) === 1;
                $emailEnabled = ((int) $userSettings->email_alert) === 1;
                $shouldSendEmail = $transactionEnabled && $emailEnabled;

                Log::info('📧 Email settings parsed', [
                    'transaction_enabled' => $transactionEnabled ? 'YES' : 'NO',
                    'email_enabled' => $emailEnabled ? 'YES' : 'NO',
                ]);
            } else {
                // No settings = send email (default behavior)
                $shouldSendEmail = true;
                Log::info('📧 No settings found - using default (SEND)');
            }

            Log::info('📧 Final email decision', [
                'should_send' => $shouldSendEmail ? 'YES' : 'NO',
            ]);

            if ($shouldSendEmail) {
                \App\Jobs\SendPaymentSuccessNotification::dispatch($payment);
                Log::info('✅ Payment success email SENT', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                ]);
            } else {
                Log::info('❌ Payment success email BLOCKED', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'reason' => 'User disabled email or transaction alerts',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment verified and records created successfully.',
                'data' => [
                    'payment' => [
                        'id' => $payment->id,
                        'payment_intent_id' => $payment->payment_intent_id,
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency,
                        'status' => $payment->status,
                        'paid_at' => $payment->paid_at->toIso8601String(),
                    ],
                    'hold' => $hold ? [
                        'id' => $hold->id,
                        'amount' => (float) $hold->amount,
                        'status' => $hold->status,
                        'hold_start_at' => $hold->hold_start_at->toIso8601String(),
                        'hold_end_at' => $hold->hold_end_at->toIso8601String(),
                        'hold_days' => $hold->hold_days,
                        'hold_hours' => $hold->hold_hours ?? 0,
                        'hold_minutes' => $hold->hold_minutes ?? 0,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Verify Payment Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Stripe payment return.
     */
    public function handlePaymentReturn(Request $request): RedirectResponse
    {
        // Log that method is called
        Log::info('=== PAYMENT RETURN CALLED ===', [
            'url' => $request->fullUrl(),
            'query_params' => $request->query(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $sessionId = $request->query('session_id');
            $status = $request->query('status'); // 'success' or 'canceled'

            Log::info('Payment return parameters', [
                'session_id' => $sessionId,
                'status' => $status,
            ]);

            if (! $sessionId) {
                Log::error('Payment return: session_id missing', [
                    'all_params' => $request->all(),
                ]);

                return redirect(config('services.stripe.payment_failed_url'));
            }

            // Retrieve checkout session from Stripe
            $session = \Stripe\Checkout\Session::retrieve([
                'id' => $sessionId,
                'expand' => ['payment_intent'],
            ]);

            $paymentIntentId = $session->payment_intent->id ?? null;

            if (! $paymentIntentId) {
                Log::error('Payment return: payment_intent_id missing', [
                    'session_id' => $sessionId,
                ]);

                return redirect(config('services.stripe.payment_failed_url'));
            }

            // Get payment intent details
            $paymentIntent = is_string($session->payment_intent)
                ? \Stripe\PaymentIntent::retrieve($paymentIntentId)
                : $session->payment_intent;

            $userId = $paymentIntent->metadata->user_id ?? null;

            if (! $userId) {
                Log::error('Payment return: user_id missing from metadata', [
                    'payment_intent_id' => $paymentIntentId,
                ]);

                return redirect(config('services.stripe.payment_failed_url'));
            }

            // Get amount and currency
            $amount = ($session->amount_total ?? 0) / 100;
            $currency = $session->currency ?? 'usd';

            // Check if payment already exists
            $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntentId))->first();

            // Handle based on payment status
            if ($status === 'success' && $paymentIntent->status === 'succeeded') {
                // SUCCESS: Create database records
                if (! $payment) {
                    // Extract card details from latest charge
                    $charge = $paymentIntent->latest_charge;
                    if (is_string($charge)) {
                        $charge = \Stripe\Charge::retrieve($charge);
                    }
                    $cardDetails = $charge?->payment_method_details?->card ?? null;

                    // Create payment record
                    $payment = Payment::create([
                        'user_id' => $userId,
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
                        'payment_method_type' => $cardDetails?->wallet?->type ?? 'card',
                    ]);

                    Log::info('✅ Payment SUCCESS - Record created', [
                        'payment_id' => $payment->id,
                        'payment_intent_id' => $paymentIntentId,
                        'amount' => $amount,
                    ]);
                } else {
                    // Update existing payment
                    $payment->update([
                        'status' => 'succeeded',
                        'paid_at' => now(),
                        'stripe_data' => $paymentIntent->toArray(),
                    ]);

                    Log::info('✅ Payment SUCCESS - Record updated', [
                        'payment_id' => $payment->id,
                    ]);
                }

                // Create payment hold if hold period data exists
                if (isset($paymentIntent->metadata->hold_period_type) && ! $payment->hold) {
                    $holdPeriodData = [
                        'hold_period_type' => $paymentIntent->metadata->hold_period_type,
                        'hold_start_at' => $paymentIntent->metadata->hold_start_at ?? null,
                        'hold_end_at' => $paymentIntent->metadata->hold_end_at ?? null,
                        'hold_days' => $paymentIntent->metadata->hold_days ?? null,
                        'hold_hours' => $paymentIntent->metadata->hold_hours ?? 0,
                        'hold_minutes' => $paymentIntent->metadata->hold_minutes ?? 0,
                        'title' => $paymentIntent->metadata->title ?? null,
                    ];

                    $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);

                    Log::info('✅ PaymentHold record created', [
                        'payment_id' => $payment->id,
                    ]);
                }

                return redirect(config('services.stripe.payment_success_url'));
            } elseif ($status === 'canceled' || $paymentIntent->status === 'canceled') {
                // CANCELED: Do NOT store in database
                Log::info('❌ Payment CANCELED - No database record created', [
                    'payment_intent_id' => $paymentIntentId,
                    'user_id' => $userId,
                ]);

                return redirect(config('services.stripe.payment_failed_url'));
            } else {
                // FAILED: Do NOT store in database
                $failureReason = $paymentIntent->last_payment_error->message ?? 'Payment failed';

                Log::error('❌ Payment FAILED - No database record created', [
                    'payment_intent_id' => $paymentIntentId,
                    'user_id' => $userId,
                    'reason' => $failureReason,
                ]);

                return redirect(config('services.stripe.payment_failed_url'));
            }
        } catch (\Exception $e) {
            Log::error('Payment return handling failed: '.$e->getMessage());

            return redirect(config('services.stripe.payment_failed_url'));
        }
    }

    /**
     * Handle Stripe webhook.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $sigHeader = $request->header('Stripe-Signature');
            $webhookSecret = config('services.stripe.webhook_secret');

            if (empty($webhookSecret)) {
                Log::warning('Stripe webhook secret not configured');

                return response()->json(['error' => 'Webhook secret not configured'], 400);
            }

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );

            // Deduplication: check if this event was already processed
            $existingEvent = StripeWebhookEvent::where('stripe_event_id', $event['id'])->first();
            if ($existingEvent && $existingEvent->status === 'processed') {
                Log::info('Duplicate webhook event skipped', ['event_id' => $event['id']]);

                return response()->json(['received' => true]);
            }

            // Record the event
            $webhookEvent = StripeWebhookEvent::updateOrCreate(
                ['stripe_event_id' => $event['id']],
                [
                    'event_type' => $event['type'],
                    'status' => 'pending',
                    'payload' => $event->toArray(),
                ]
            );

            // Handle different event types
            $eventArray = $event->toArray();

            switch ($event['type']) {
                case 'checkout.session.completed':
                    $this->webhookService->handleCheckoutSessionCompleted($eventArray);
                    break;

                case 'payment_intent.succeeded':
                    $this->webhookService->handlePaymentIntentSucceeded($eventArray);
                    break;

                case 'payment_intent.payment_failed':
                    $this->webhookService->handlePaymentIntentFailed($eventArray);
                    break;

                case 'payment_intent.canceled':
                    $this->webhookService->handlePaymentIntentCanceled($eventArray);
                    break;

                default:
                    Log::info('Unhandled webhook event type: '.$event['type']);
            }

            // Mark event as processed
            $webhookEvent->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            return response()->json(['received' => true]);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook handling failed: '.$e->getMessage());

            // Mark event as failed if we have a record
            if (isset($webhookEvent)) {
                $webhookEvent->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'retry_count' => $webhookEvent->retry_count + 1,
                    'last_retry_at' => now(),
                ]);
            }

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
