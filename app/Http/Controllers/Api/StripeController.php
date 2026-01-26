<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\CreateConnectAccountRequest;
use App\Http\Requests\Stripe\CreatePaymentIntentRequest;
use App\Http\Requests\Stripe\GetOnboardingLinkRequest;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\StripeWebhookEvent;
use App\Services\PaymentHoldService;
use App\Services\StripeService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

            // Check if user already has connect account
            $existingAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if ($existingAccount) {
                return response()->json([
                    'success' => true,
                    'message' => 'Stripe Connect account already exists.',
                    'data' => [
                        'connect_account_id' => $existingAccount->connect_account_id,
                        'status' => $existingAccount->status,
                        'onboarding_url' => $existingAccount->onboarding_url,
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
     * Get onboarding link for Stripe Connect account.
     */
    public function getOnboardingLink(GetOnboardingLinkRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if (! $connectAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe Connect account not found. Please create one first.',
                ], 404);
            }

            $onboardingUrl = $this->stripeService->getOnboardingLink($connectAccount);

            return response()->json([
                'success' => true,
                'message' => 'Onboarding link generated successfully.',
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
     * Handle Stripe Connect OAuth callback after onboarding completion.
     */
    public function handleConnectCallback(Request $request): JsonResponse
    {
        try {
            // Set Stripe API key
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $accountId = $request->query('account');

            if (! $accountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account ID is required.',
                ], 400);
            }

            // Find the connect account
            $connectAccount = StripeConnectAccount::where('connect_account_id', $accountId)->first();

            if (! $connectAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe Connect account not found.',
                ], 404);
            }

            // Retrieve account details from Stripe
            $account = \Stripe\Account::retrieve($accountId);

            // Update connect account status
            $connectAccount->update([
                'status' => $account->details_submitted ? 'active' : 'pending',
                'payouts_enabled' => $account->payouts_enabled ?? false,
                'stripe_data' => $account->toArray(),
                'verified_at' => $account->details_submitted ? now() : null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stripe Connect account verified successfully.',
                'data' => [
                    'connect_account_id' => $connectAccount->connect_account_id,
                    'status' => $connectAccount->status,
                    'payouts_enabled' => $connectAccount->payouts_enabled,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe Connect Callback Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process Stripe Connect callback.',
            ], 500);
        }
    }

    /**
     * Create PaymentIntent with hold period data.
     */
    public function createPaymentIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Prepare hold period data
            $holdPeriodData = [];
            if ($request->hold_period_type) {
                $holdPeriodData = [
                    'user_id' => $user->id,
                    'hold_period_type' => $request->hold_period_type,
                    'hold_start_at' => $request->hold_start_at ?? now()->toIso8601String(),
                    'hold_end_at' => $request->hold_end_at ?? null,
                    'hold_days' => $request->hold_days ?? 30,
                ];

                // Calculate end date if not provided
                if (! $request->hold_end_at && $request->hold_period_type !== 'custom') {
                    $days = match ($request->hold_period_type) {
                        '1_month' => 30,
                        '2_months' => 60,
                        '6_months' => 180,
                        '1_year' => 365,
                        default => 30,
                    };
                    $holdPeriodData['hold_days'] = $days;
                    $holdPeriodData['hold_end_at'] = now()->addDays($days)->toIso8601String();
                }
            }

            // Prepare return URL (use provided URL or default from config)
            $returnUrl = $request->return_url ?? config('services.stripe.payment_return_url');

            // Create payment intent
            $paymentIntent = $this->stripeService->createPaymentIntent([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'return_url' => $returnUrl,
                ...$holdPeriodData,
            ]);

            // Create payment record
            Payment::create([
                'user_id' => $user->id,
                'payment_intent_id' => $paymentIntent['payment_intent_id'],
                'amount' => $request->amount / 100, // Convert cents to dollars
                'currency' => $request->currency,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment intent created successfully.',
                'data' => $paymentIntent,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Payment Intent Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent.',
            ], 500);
        }
    }

    /**
     * Test Payment Intent with Card (For Testing Only).
     * This route creates payment intent and confirms it with test card automatically.
     */
    public function testPaymentWithCard(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Ensure JSON is parsed - Laravel should do this automatically, but let's be explicit
            // If Content-Type is application/json, Laravel parses it automatically
            // But if it's not set, we need to parse manually
            $contentType = $request->header('Content-Type');
            if (str_contains($contentType ?? '', 'application/json')) {
                // JSON is already parsed by Laravel
                $requestData = $request->all();
            } else {
                // Try to parse JSON from raw body
                $rawBody = $request->getContent();
                if (! empty($rawBody)) {
                    $jsonData = json_decode($rawBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        $request->merge($jsonData);
                    }
                }
            }

            // Validate request
            $validated = $request->validate([
                'amount' => ['required', 'integer', 'min:100'],
                'currency' => ['nullable', 'string', 'in:usd,eur,gbp'],
                'hold_period_type' => ['nullable', 'string', 'in:1_month,2_months,6_months,1_year,custom'],
                'hold_start_at' => ['nullable', 'date'],
                'hold_end_at' => ['nullable', 'date', 'after:hold_start_at'],
                'hold_days' => ['nullable', 'integer', 'min:30'],
            ]);

            $amount = $validated['amount'];
            $currency = $validated['currency'] ?? 'usd';

            // Prepare hold period data
            $holdPeriodData = [];
            if (! empty($validated['hold_period_type'])) {
                $holdPeriodData = [
                    'user_id' => $user->id,
                    'hold_period_type' => $validated['hold_period_type'],
                    'hold_start_at' => $validated['hold_start_at'] ?? now()->toIso8601String(),
                    'hold_end_at' => $validated['hold_end_at'] ?? null,
                    'hold_days' => $validated['hold_days'] ?? 30,
                ];

                // Calculate end date if not provided
                if (! isset($validated['hold_end_at']) && $validated['hold_period_type'] !== 'custom') {
                    $days = match ($validated['hold_period_type']) {
                        '1_month' => 30,
                        '2_months' => 60,
                        '6_months' => 180,
                        '1_year' => 365,
                        default => 30,
                    };
                    $holdPeriodData['hold_days'] = $days;
                    $holdPeriodData['hold_end_at'] = now()->addDays($days)->toIso8601String();
                }
            }

            // Prepare metadata for hold period
            $metadata = [
                'user_id' => (string) $user->id,
            ];

            if (isset($holdPeriodData['hold_period_type'])) {
                $metadata['hold_period_type'] = $holdPeriodData['hold_period_type'];
                $metadata['hold_start_at'] = $holdPeriodData['hold_start_at'];
                $metadata['hold_end_at'] = $holdPeriodData['hold_end_at'];
                $metadata['hold_days'] = (string) $holdPeriodData['hold_days'];
            }

            // Step 1: Create payment intent and confirm using Stripe's test approach
            // For testing, we'll create PaymentIntent and confirm it using Stripe's test payment method
            // This works without needing raw card data APIs enabled

            // Create payment intent first
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => $metadata,
                'payment_method_types' => ['card'],
            ]);

            // For test mode: Create payment method using test card
            // Note: This requires test mode and may need account configuration
            // Alternative: Use Stripe CLI to confirm: stripe payment_intents confirm <pi_id> --payment-method=pm_card_visa
            try {
                // Try to create payment method with test card (works in test mode if enabled)
                $paymentMethod = \Stripe\PaymentMethod::create([
                    'type' => 'card',
                    'card' => [
                        'number' => '4242424242424242',
                        'exp_month' => 12,
                        'exp_year' => date('Y') + 1,
                        'cvc' => '123',
                    ],
                ]);

                // Confirm payment intent with the payment method
                $confirmedPaymentIntent = $paymentIntent->confirm([
                    'payment_method' => $paymentMethod->id,
                    'return_url' => config('app.url').'/stripe/return',
                ]);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // If raw card data is not allowed, create payment intent without confirming
                // User can confirm it manually via Stripe Dashboard or CLI
                Log::warning('Test Payment: Cannot create PaymentMethod with raw card data', [
                    'error' => $e->getMessage(),
                    'payment_intent_id' => $paymentIntent->id,
                ]);

                // Return payment intent for manual confirmation
                return response()->json([
                    'success' => false,
                    'message' => 'Test payment requires manual confirmation. Raw card data APIs not enabled.',
                    'instructions' => [
                        'option_1' => 'Enable in Stripe Dashboard: Settings → API → "Process payments unsafely"',
                        'option_2' => 'Use Stripe CLI: stripe payment_intents confirm '.$paymentIntent->id.' --payment-method=pm_card_visa',
                        'option_3' => 'Confirm manually in Stripe Dashboard',
                    ],
                    'data' => [
                        'payment_intent_id' => $paymentIntent->id,
                        'client_secret' => $paymentIntent->client_secret,
                        'status' => $paymentIntent->status,
                    ],
                ], 400);
            }

            $paymentIntentId = $confirmedPaymentIntent->id;

            // Store hold period data in cache for webhook
            if (isset($holdPeriodData['hold_period_type'])) {
                Cache::put(
                    "payment_intent_hold_{$paymentIntentId}",
                    [
                        'hold_period_type' => $holdPeriodData['hold_period_type'] ?? null,
                        'hold_start_at' => $holdPeriodData['hold_start_at'] ?? null,
                        'hold_end_at' => $holdPeriodData['hold_end_at'] ?? null,
                        'hold_days' => $holdPeriodData['hold_days'] ?? null,
                    ],
                    now()->addDays(7)
                );
            }

            // Step 5: Create payment record
            $payment = Payment::create([
                'user_id' => $user->id,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $amount / 100, // Convert cents to dollars
                'currency' => $currency,
                'status' => $confirmedPaymentIntent->status === 'succeeded' ? 'succeeded' : 'pending',
                'paid_at' => $confirmedPaymentIntent->status === 'succeeded' ? now() : null,
                'stripe_data' => $confirmedPaymentIntent->toArray(),
            ]);

            // Step 6: If payment succeeded, create payment hold directly (independent of webhook)
            $holdCreated = false;
            if ($confirmedPaymentIntent->status === 'succeeded') {
                // Check if payment hold already exists
                $existingHold = PaymentHold::where('payment_id', $payment->id)->first();

                if (! $existingHold) {
                    // Get hold period data from cache or metadata
                    $holdPeriodDataForHold = $this->stripeService->getHoldPeriodData($paymentIntentId);

                    // Create payment hold directly
                    if ($holdPeriodDataForHold) {
                        $this->paymentHoldService->createFromPayment($payment, $holdPeriodDataForHold);
                    } else {
                        // Default 30 days if no hold period data
                        $this->paymentHoldService->createFromPayment($payment);
                    }
                    $holdCreated = true;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Test payment completed successfully.',
                'data' => [
                    'payment_intent_id' => $paymentIntentId,
                    'payment_status' => $confirmedPaymentIntent->status,
                    'payment_id' => $payment->id,
                    'amount' => $amount / 100,
                    'currency' => $currency,
                    'hold_created' => $holdCreated,
                    'hold_period' => $holdPeriodData['hold_period_type'] ?? null,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Test Payment Validation Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Stripe\Exception\CardException $e) {
            Log::error('Test Payment Card Error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Payment failed: '.$e->getError()->message,
                'error' => $e->getError()->toArray(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Test Payment Failed: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process test payment: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Payment Intent return after redirect-based payment (3D Secure, etc.).
     */
    public function handlePaymentReturn(Request $request)
    {
        try {
            $paymentIntentId = $request->query('payment_intent');
            $paymentIntentClientSecret = $request->query('payment_intent_client_secret');

            if (! $paymentIntentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment Intent ID is required.',
                ], 400);
            }

            // Retrieve payment intent from Stripe
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            // Find payment record
            $payment = Payment::where('payment_intent_id', $paymentIntentId)->first();

            if (! $payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment record not found.',
                ], 404);
            }

            // Update payment status based on Stripe status
            $status = match ($paymentIntent->status) {
                'succeeded' => 'succeeded',
                'processing' => 'processing',
                'requires_payment_method', 'requires_confirmation', 'requires_action' => 'pending',
                'canceled' => 'canceled',
                default => 'failed',
            };

            $payment->update([
                'status' => $status,
                'paid_at' => $paymentIntent->status === 'succeeded' ? now() : null,
                'stripe_data' => $paymentIntent->toArray(),
            ]);

            // Create payment hold if payment succeeded (independent of webhook)
            if ($paymentIntent->status === 'succeeded') {
                // Check if payment hold already exists
                $existingHold = PaymentHold::where('payment_id', $payment->id)->first();

                if (! $existingHold) {
                    // Get hold period data from payment intent metadata or cache
                    $holdPeriodData = $this->stripeService->getHoldPeriodData($paymentIntentId);

                    // Create payment hold
                    if ($holdPeriodData) {
                        $this->paymentHoldService->createFromPayment($payment, $holdPeriodData);
                    } else {
                        // Default 30 days if no hold period data
                        $this->paymentHoldService->createFromPayment($payment);
                    }
                }
            }

            // Return JSON response with redirect URLs from config (hardcoded)
            $redirectUrl = match ($paymentIntent->status) {
                'succeeded' => config('services.stripe.payment_success_url'),
                'canceled', 'requires_payment_method' => config('services.stripe.payment_failed_url'),
                default => config('services.stripe.payment_failed_url'),
            };

            return response()->json([
                'success' => $paymentIntent->status === 'succeeded',
                'message' => match ($paymentIntent->status) {
                    'succeeded' => 'Payment completed successfully.',
                    'canceled' => 'Payment was canceled.',
                    'requires_payment_method' => 'Payment failed. Please try again.',
                    default => 'Payment is being processed.',
                },
                'data' => [
                    'payment_intent_id' => $paymentIntentId,
                    'status' => $paymentIntent->status,
                    'payment_id' => $payment->id,
                    'redirect_url' => $redirectUrl,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Payment Return Handler Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment return.',
            ], 500);
        }
    }

    /**
     * Handle Stripe webhook events.
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );

            // Check if event already processed (idempotency)
            $existingEvent = StripeWebhookEvent::where('stripe_event_id', $event->id)->first();

            if ($existingEvent && $existingEvent->status === 'processed') {
                Log::info("Webhook event already processed: {$event->id}");

                return response()->json(['received' => true]);
            }

            // Create webhook event record
            $webhookEvent = StripeWebhookEvent::updateOrCreate(
                ['stripe_event_id' => $event->id],
                [
                    'event_type' => $event->type,
                    'status' => 'pending',
                    'payload' => $event->toArray(),
                ]
            );

            // Process event based on type
            // NOTE: Webhook is ONLY used for payout transfers, NOT for payment flow
            // Payment success/failure is handled independently via handlePaymentReturn
            $eventArray = $event->toArray();

            match ($event->type) {
                // Transfer events only - for payout notifications
                'transfer.created' => $this->webhookService->handleTransferCreated($eventArray),
                'transfer.failed' => $this->webhookService->handleTransferFailed($eventArray),
                'transfer.canceled' => $this->webhookService->handleTransferCanceled($eventArray),
                // Account updates (for Connect account status)
                'account.updated' => $this->webhookService->handleAccountUpdated($eventArray),
                // Payment events are ignored - handled via handlePaymentReturn
                default => Log::info("Unhandled webhook event type: {$event->type}"),
            };

            // Update webhook event status
            $webhookEvent->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            return response()->json(['received' => true]);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed: '.$e->getMessage());

            // Update webhook event status to failed
            if (isset($event)) {
                StripeWebhookEvent::where('stripe_event_id', $event->id)->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
