<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\CreateConnectAccountRequest;
use App\Http\Requests\Stripe\CreatePaymentIntentRequest;
use App\Http\Requests\Stripe\GetOnboardingLinkRequest;
use App\Models\Payment;
use App\Models\StripeConnectAccount;
use App\Models\StripeWebhookEvent;
use App\Services\StripeService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    public function __construct(
        protected StripeService $stripeService,
        protected WebhookService $webhookService
    ) {
    }

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

            // Create payment intent
            $paymentIntent = $this->stripeService->createPaymentIntent([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'currency' => $request->currency,
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
            $eventArray = $event->toArray();

            match ($event->type) {
                'payment_intent.succeeded' => $this->webhookService->handlePaymentIntentSucceeded($eventArray),
                'payment_intent.payment_failed' => $this->webhookService->handlePaymentIntentFailed($eventArray),
                'account.updated' => $this->webhookService->handleAccountUpdated($eventArray),
                'transfer.created' => $this->webhookService->handleTransferCreated($eventArray),
                'transfer.failed' => $this->webhookService->handleTransferFailed($eventArray),
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
