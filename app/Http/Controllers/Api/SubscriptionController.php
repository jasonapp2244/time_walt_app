<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\SubscribeRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\FreeTierService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected FreeTierService $freeTier
    ) {}

    /**
     * Plans to show on the subscription screen.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Subscription plans retrieved successfully.',
            'data' => [
                'plans' => SubscriptionPlan::available()->get()->map->toApiArray()->values(),
            ],
        ]);
    }

    /**
     * Whether the user is subscribed, and how much free allowance is left.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Subscription status retrieved successfully.',
            'data' => $this->statusPayload($request->user()),
        ]);
    }

    /**
     * Start a subscription. Returns the Payment Sheet keys; access starts once
     * the payment succeeds and /confirm (or the webhook) sees it as active.
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isSubscribed()) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an active subscription.',
                'data' => $this->statusPayload($user),
            ], 409);
        }

        $plan = SubscriptionPlan::findOrFail($request->validated('plan_id'));

        try {
            $data = $this->subscriptionService->subscribe($user, $plan);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Subscription start failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to start the subscription. Please try again later.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Subscription created. Complete the payment to activate it.',
            'data' => $data,
        ]);
    }

    /**
     * Called after the Payment Sheet succeeds. Reads the subscription back
     * from Stripe so the blocked action can be retried straight away.
     */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $subscription = $this->subscriptionService->confirm($user);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Subscription confirm failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to confirm the subscription. Please try again.',
            ], 500);
        }

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No subscription found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => $subscription->isEntitled()
                ? 'Your subscription is active.'
                : 'Payment is still processing. Check again in a few seconds.',
            'data' => $this->statusPayload($user->fresh()),
        ]);
    }

    /**
     * Stop renewing at the end of the paid period.
     */
    public function cancel(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $user->activeSubscription;

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have an active subscription.',
            ], 404);
        }

        if (! $subscription->cancel_at_period_end) {
            try {
                $this->subscriptionService->cancelAtPeriodEnd($subscription);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Subscription cancel failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Unable to cancel the subscription. Please try again later.',
                ], 500);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Your subscription will end at the close of the current period.',
            'data' => $this->statusPayload($user->fresh()),
        ]);
    }

    /**
     * A Stripe Customer Portal link to change the card or see invoices.
     */
    public function portal(Request $request): JsonResponse
    {
        try {
            $url = $this->subscriptionService->portalUrl($request->user());
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Customer portal session failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to open subscription management. Please try again later.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer portal session created.',
            'data' => ['url' => $url],
        ]);
    }

    private function statusPayload(User $user): array
    {
        $tz = $user->timezone ?? 'UTC';
        $subscription = $user->activeSubscription ?? $user->subscriptions()->first();
        $lock = $this->freeTier->quota($user, FreeTierService::LOCK);
        $withdraw = $this->freeTier->quota($user, FreeTierService::WITHDRAW);

        return [
            'is_subscribed' => $lock['subscribed'],
            'subscription' => $subscription ? $this->subscriptionArray($subscription, $tz) : null,
            'locks_used' => $lock['used'],
            'lock_limit' => $lock['limit'],
            'locks_left' => $lock['left'],
            'withdrawals_used' => $withdraw['used'],
            'withdraw_limit' => $withdraw['limit'],
            'withdrawals_left' => $withdraw['left'],
        ];
    }

    private function subscriptionArray(Subscription $subscription, string $tz): array
    {
        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'is_active' => $subscription->isEntitled(),
            'plan' => $subscription->plan?->toApiArray(),
            'current_period_end' => $subscription->current_period_end?->setTimezone($tz)->toIso8601String(),
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
        ];
    }
}
