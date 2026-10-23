<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\FreeTierService;
use App\Services\PaymentSheetService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a user who has spent the free allowance and has no subscription,
 * with a 402 the app turns into the subscription plans screen.
 *
 * Usage: ->middleware('quota:lock') / ->middleware('quota:withdraw').
 *
 * lock:     the lock is counted when its hold is created, because a user may
 *           abandon the payment. On the free allowance the user's other
 *           unpaid deposits are cancelled first, so only one can be in flight.
 * withdraw: a free withdrawal is claimed before the controller runs (so
 *           parallel requests cannot share the last one) and given back if
 *           the request does not succeed.
 */
class EnsureFreeQuotaOrSubscribed
{
    public function __construct(
        protected FreeTierService $freeTier,
        protected PaymentSheetService $paymentSheet
    ) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();

        if ($action === FreeTierService::LOCK) {
            $quota = $this->freeTier->quota($user, FreeTierService::LOCK);

            if ($quota['blocked']) {
                return response()->json($this->freeTier->blockedPayload($quota), 402);
            }

            if ($quota['left'] === null) {
                return $next($request); // Subscribed, or locks are not limited.
            }

            return $this->freeDeposit($request, $next, $quota);
        }

        $claimed = $this->freeTier->claimWithdrawal($user);

        if ($claimed === false) {
            $quota = $this->freeTier->quota($user, FreeTierService::WITHDRAW);

            return response()->json($this->freeTier->blockedPayload($quota), 402);
        }

        $response = $next($request);

        if ($claimed === true && ! $this->succeeded($response)) {
            $this->freeTier->releaseWithdrawal($user);
        }

        return $response;
    }

    /**
     * A deposit on the free allowance. Runs with the user row locked so two
     * requests cannot both start the last free deposit, and counts deposits
     * already charged but not yet recorded, which the counter cannot see yet.
     */
    private function freeDeposit(Request $request, Closure $next, array $quota): Response
    {
        try {
            return DB::transaction(function () use ($request, $next, $quota) {
                User::whereKey($request->user()->id)->lockForUpdate()->first(['id']);

                $quota['used'] += $this->paymentSheet->settleOpenDeposits($request->user());

                if ($quota['used'] >= $quota['limit']) {
                    return response()->json($this->freeTier->blockedPayload($quota), 402);
                }

                return $next($request);
            });
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Could not check open deposits before a free lock', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent. Please try again.',
            ], 503);
        }
    }

    private function succeeded(Response $response): bool
    {
        if (! $response->isSuccessful()) {
            return false;
        }

        return ! ($response instanceof JsonResponse && ($response->getData(true)['success'] ?? null) === false);
    }
}
