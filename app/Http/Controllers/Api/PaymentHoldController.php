<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stripe\WithdrawPayoutRequest;
use App\Jobs\SendPayoutRequestNotification;
use App\Models\PaymentHold;
use App\Models\StripeConnectAccount;
use App\Models\Transfer;
use App\Models\UserNotificationSetting;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentHoldController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

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

            // Send email notification to user and admin (check both transaction_alert and email_alert)
            $userSettings = UserNotificationSetting::where('user_id', $hold->user_id)->first();
            $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

            if ($shouldSendEmail) {
                SendPayoutRequestNotification::dispatch($transfer);
            }

            Log::info('Payout request created', [
                'transfer_id' => $transfer->id,
                'hold_id' => $hold->id,
                'user_id' => $hold->user_id,
                'amount' => $transfer->amount,
            ]);

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
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe-specific errors
            Log::error('Stripe Payout Request Failed', [
                'error' => $e->getMessage(),
                'hold_id' => $holdId ?? null,
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process payout request. Please try again later or contact support.',
            ], 500);
        } catch (\Exception $e) {
            // General errors
            Log::error('User Payout Request Failed', [
                'error' => $e->getMessage(),
                'hold_id' => $holdId ?? null,
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your request. Please try again later.',
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
                        'available_for_payout' => $holds->filter(fn ($h) => $this->canRequestPayout($h))->count(),
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
     * Withdraw specific amount from available holds (partial payout).
     */
    public function withdraw(WithdrawPayoutRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $requestedAmount = $request->amount;

            // Find all available holds for this user
            $availableHolds = PaymentHold::where('user_id', $user->id)
                ->where('status', 'ready_for_transfer')
                ->whereDoesntHave('transfer')
                ->orderBy('hold_end_at', 'asc')
                ->get();

            if ($availableHolds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No funds available for withdrawal. Hold period not completed yet.',
                ], 400);
            }

            // Calculate total available amount
            $totalAvailable = $availableHolds->sum('amount');

            if ($requestedAmount > $totalAvailable) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient available funds. Available: \${$totalAvailable}, Requested: \${$requestedAmount}",
                    'data' => [
                        'available_amount' => (float) $totalAvailable,
                        'requested_amount' => (float) $requestedAmount,
                    ],
                ], 400);
            }

            // Check if user has Stripe Connect account
            $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if (! $connectAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe Connect account not found. Please complete account setup.',
                ], 400);
            }

            DB::beginTransaction();

            try {
                $remainingAmount = $requestedAmount;
                $processedHolds = [];

                // Process holds until we've transferred the requested amount
                foreach ($availableHolds as $hold) {
                    if ($remainingAmount <= 0) {
                        break;
                    }

                    $amountFromThisHold = min($remainingAmount, $hold->amount);

                    // Get currency from payment
                    $payment = $hold->payment;
                    $currency = $payment ? strtolower($payment->currency) : 'usd';

                    // Create Stripe transfer for this amount
                    \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

                    $transfer = \Stripe\Transfer::create([
                        'amount' => (int) ($amountFromThisHold * 100),
                        'currency' => $currency,
                        'destination' => $connectAccount->connect_account_id,
                        'description' => "Partial withdrawal from hold #{$hold->id}",
                        'metadata' => [
                            'hold_id' => $hold->id,
                            'user_id' => $user->id,
                            'payment_id' => $payment ? $payment->id : null,
                            'withdrawal_type' => 'partial',
                        ],
                    ]);

                    // Create transfer record
                    $transferRecord = Transfer::create([
                        'hold_id' => $hold->id,
                        'user_id' => $user->id,
                        'stripe_transfer_id' => $transfer->id,
                        'stripe_connect_account_id' => $connectAccount->connect_account_id,
                        'amount' => $amountFromThisHold,
                        'currency' => $currency,
                        'status' => 'pending',
                        'transfer_type' => 'user_requested_partial',
                        'admin_id' => null,
                        'stripe_data' => $transfer->toArray(),
                    ]);

                    // Update hold status
                    $hold->update([
                        'status' => 'transferred',
                        'transferred_at' => now(),
                    ]);

                    $processedHolds[] = [
                        'hold_id' => $hold->id,
                        'amount' => (float) $amountFromThisHold,
                        'transfer_id' => $transferRecord->id,
                    ];

                    $remainingAmount -= $amountFromThisHold;

                    // Send email notification (check both transaction_alert and email_alert)
                    $userSettings = UserNotificationSetting::where('user_id', $user->id)->first();
                    $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

                    if ($shouldSendEmail) {
                        SendPayoutRequestNotification::dispatch($transferRecord);
                    }
                }

                DB::commit();

                Log::info('Partial withdrawal completed', [
                    'user_id' => $user->id,
                    'requested_amount' => $requestedAmount,
                    'processed_holds' => count($processedHolds),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => "Withdrawal request for \${$requestedAmount} submitted successfully.",
                    'data' => [
                        'total_amount' => (float) $requestedAmount,
                        'holds_processed' => $processedHolds,
                        'status' => 'pending',
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe withdrawal failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process withdrawal request. Please try again later or contact support.',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your withdrawal. Please try again later.',
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
