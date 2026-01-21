<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentHoldController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {
    }

    /**
     * Get user's payment holds.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $holds = PaymentHold::where('user_id', $user->id)
                ->with(['payment', 'transfer'])
                ->orderBy('created_at', 'desc')
                ->get();

            $formattedHolds = $holds->map(function ($hold) {
                return [
                    'id' => $hold->id,
                    'amount' => (float) $hold->amount,
                    'status' => $hold->status,
                    'hold_start_at' => $hold->hold_start_at?->toIso8601String(),
                    'hold_end_at' => $hold->hold_end_at?->toIso8601String(),
                    'hold_days' => $hold->hold_days,
                    'hold_period_type' => $hold->hold_period_type,
                    'ready_at' => $hold->ready_at?->toIso8601String(),
                    'transferred_at' => $hold->transferred_at?->toIso8601String(),
                    'can_request_payout' => $this->canRequestPayout($hold),
                    'payment' => [
                        'id' => $hold->payment->id,
                        'payment_intent_id' => $hold->payment->payment_intent_id,
                        'status' => $hold->payment->status,
                    ],
                    'transfer' => $hold->transfer ? [
                        'id' => $hold->transfer->id,
                        'status' => $hold->transfer->status,
                        'stripe_transfer_id' => $hold->transfer->stripe_transfer_id,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment holds retrieved successfully.',
                'data' => $formattedHolds,
            ]);
        } catch (\Exception $e) {
            Log::error('Get Payment Holds Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment holds.',
            ], 500);
        }
    }

    /**
     * Request payout for a payment hold (if ready for transfer).
     */
    public function requestPayout(Request $request, int $holdId): JsonResponse
    {
        try {
            $user = $request->user();

            // Find payment hold
            $hold = PaymentHold::where('id', $holdId)
                ->where('user_id', $user->id)
                ->first();

            if (! $hold) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment hold not found.',
                ], 404);
            }

            // Check if already transferred
            if ($hold->status === 'transferred') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payout already completed for this hold.',
                ], 400);
            }

            // Check if transfer already exists
            if ($hold->transfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payout request already exists for this hold.',
                    'data' => [
                        'transfer_id' => $hold->transfer->id,
                        'status' => $hold->transfer->status,
                    ],
                ], 400);
            }

            // Check if hold is ready for transfer
            if (! $this->canRequestPayout($hold)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hold period not completed yet. Payout will be available after hold period ends.',
                    'data' => [
                        'hold_end_at' => $hold->hold_end_at?->toIso8601String(),
                        'days_remaining' => $hold->hold_end_at ? max(0, now()->diffInDays($hold->hold_end_at, false)) : null,
                    ],
                ], 400);
            }

            // Update hold status to ready_for_transfer if still holding
            if ($hold->status === 'holding') {
                $hold->update([
                    'status' => 'ready_for_transfer',
                    'ready_at' => now(),
                ]);
            }

            // Create transfer
            $transfer = $this->stripeService->createTransfer($hold, 'user_requested');

            return response()->json([
                'success' => true,
                'message' => 'Payout request submitted successfully. Transfer will be processed shortly.',
                'data' => [
                    'transfer_id' => $transfer->id,
                    'stripe_transfer_id' => $transfer->stripe_transfer_id,
                    'status' => $transfer->status,
                    'amount' => $transfer->amount,
                    'currency' => $transfer->currency,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('User Payout Request Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to process payout request: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's payment holds summary/statistics.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $holds = PaymentHold::where('user_id', $user->id)->get();

            // Calculate totals by status
            $totalLocked = $holds->where('status', 'holding')->sum('amount');
            $totalReadyForTransfer = $holds->where('status', 'ready_for_transfer')->sum('amount');
            $totalTransferred = $holds->where('status', 'transferred')->sum('amount');

            // Counts
            $lockedCount = $holds->where('status', 'holding')->count();
            $readyCount = $holds->where('status', 'ready_for_transfer')->count();
            $transferredCount = $holds->where('status', 'transferred')->count();

            // Total amount (all statuses)
            $totalAmount = $holds->sum('amount');

            // Available for payout (ready_for_transfer + holding with period complete)
            $availableForPayout = $holds->filter(function ($hold) {
                return $this->canRequestPayout($hold);
            })->sum('amount');

            // Upcoming (holding with period not complete)
            $upcomingPayouts = $holds->where('status', 'holding')
                ->filter(function ($hold) {
                    return $hold->hold_end_at && $hold->hold_end_at->isFuture();
                })
                ->sum('amount');

            return response()->json([
                'success' => true,
                'message' => 'Payment holds summary retrieved successfully.',
                'data' => [
                    'summary' => [
                        'total_amount' => (float) $totalAmount,
                        'total_locked' => (float) $totalLocked,
                        'total_ready_for_transfer' => (float) $totalReadyForTransfer,
                        'total_transferred' => (float) $totalTransferred,
                        'available_for_payout' => (float) $availableForPayout,
                        'upcoming_payouts' => (float) $upcomingPayouts,
                    ],
                    'counts' => [
                        'total' => $holds->count(),
                        'locked' => $lockedCount,
                        'ready_for_transfer' => $readyCount,
                        'transferred' => $transferredCount,
                        'available_for_payout' => $holds->filter(fn($h) => $this->canRequestPayout($h))->count(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Payment Holds Summary Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment holds summary.',
            ], 500);
        }
    }

    /**
     * Check if user can request payout for this hold.
     */
    protected function canRequestPayout(PaymentHold $hold): bool
    {
        // Already transferred
        if ($hold->status === 'transferred') {
            return false;
        }

        // Already has transfer
        if ($hold->transfer) {
            return false;
        }

        // Check if hold period is complete
        if ($hold->hold_end_at && $hold->hold_end_at->isFuture()) {
            return false;
        }

        // If status is ready_for_transfer or holding (and period complete)
        return $hold->status === 'ready_for_transfer' || 
               ($hold->status === 'holding' && $hold->hold_end_at && $hold->hold_end_at->isPast());
    }
}
