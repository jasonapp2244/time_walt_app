<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\CreateConnectAccountRequest;
use App\Http\Requests\Stripe\CreatePaymentIntentRequest;
use App\Http\Requests\Stripe\GetOnboardingLinkRequest;
use App\Models\Payment;
use App\Models\StripeConnectAccount;
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

            $connectAccount = StripeConnectAccount::where('connect_account_id', $accountId)->first();

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
                    'hold_days' => $request->hold_days,
                ];
            }

            // Create Checkout Session (Payment and PaymentHold will be created via webhook when payment succeeds)
            $checkoutSession = $this->stripeService->createPaymentIntent([
                'user_id' => $request->user()->id,
                'amount' => $amountInCents,
                'currency' => $request->currency,
                'return_url' => $request->return_url,
                ...$holdPeriodData,
            ]);

            return response()->json([
                'success' => true,
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
     * Handle Stripe payment return.
     */
    public function handlePaymentReturn(Request $request): RedirectResponse
    {
        return redirect(config('services.stripe.payment_success_url'));
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

            return response()->json(['received' => true]);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed: '.$e->getMessage());

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook handling failed: '.$e->getMessage());

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }
}
