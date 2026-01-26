<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\CreateConnectAccountRequest;
use App\Http\Requests\Stripe\CreatePaymentIntentRequest;
use App\Http\Requests\Stripe\GetOnboardingLinkRequest;
use App\Http\Requests\Stripe\TestPaymentRequest;
use App\Jobs\SendPaymentFailedNotification;
use App\Jobs\SendPaymentSuccessNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\StripeWebhookEvent;
use App\Models\UserNotificationSetting;
use App\Services\PaymentHoldService;
use App\Services\StripeService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

            $paymentIntent = $this->stripeService->createPaymentIntent([
                'user_id' => $request->user()->id,
                'amount' => $amountInCents,
                'currency' => $request->currency,
                'return_url' => $request->return_url,
            ]);

            Payment::create([
                'user_id' => $request->user()->id,
                'payment_intent_id' => $paymentIntent['payment_intent_id'],
                'amount' => $request->amount,
                'currency' => $request->currency,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
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
     * Test payment with card details.
     */
    public function testPayment(TestPaymentRequest $request): JsonResponse
    {
        try {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $amountInCents = (int) round($request->amount * 100);

            $paymentMethod = \Stripe\PaymentMethod::create([
                'type' => 'card',
                'card' => [
                    'number' => preg_replace('/\s+/', '', $request->card_number),
                    'exp_month' => $request->exp_month,
                    'exp_year' => $request->exp_year,
                    'cvc' => $request->cvc,
                ],
            ]);

            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amountInCents,
                'currency' => $request->currency,
                'payment_method' => $paymentMethod->id,
                'confirm' => true,
            ]);

            return response()->json([
                'success' => true,
                'status' => $paymentIntent->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Test Payment Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
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
        return response()->json(['received' => true]);
    }
}
