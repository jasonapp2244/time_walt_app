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
     * Get user's transaction history (checkout & transfer) with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get per_page from request, default to 15
            $perPage = $request->input('per_page', 15);
            $perPage = min(max((int) $perPage, 1), 100); // Between 1 and 100

            $holds = PaymentHold::where('user_id', $user->id)
                ->with(['payment', 'transfer'])
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            // Build transaction history
            $transactions = [];

            foreach ($holds as $hold) {
                // Calculate hold duration details
                $holdDuration = $this->calculateHoldDuration($hold);

                // TRANSACTION IN: Checkout Success (Money Coming In)
                $transactions[] = [
                    'transaction_type' => 'checkout',
                    'transaction_id' => "CHK-{$hold->id}",
                    'hold_id' => $hold->id,
                    'amount' => (float) $hold->amount,
                    'currency' => $hold->payment ? strtoupper($hold->payment->currency) : 'USD',
                    'status' => $hold->payment ? $hold->payment->status : 'unknown',
                    'date' => $hold->created_at->toIso8601String(),
                    'description' => 'Checkout payment received',
                    'hold_duration' => $holdDuration,
                    'payment_details' => [
                        'payment_id' => $hold->payment->id,
                        'payment_intent_id' => $hold->payment->payment_intent_id,
                        'hold_status' => $hold->status,
                        'can_request_payout' => $this->canRequestPayout($hold),
                    ],
                ];

                // TRANSACTION OUT: Transfer (Money Going Out)
                if ($hold->transfer) {
                    $transactions[] = [
                        'transaction_type' => 'transfer',
                        'transaction_id' => "TRF-{$hold->transfer->id}",
                        'hold_id' => $hold->id,
                        'amount' => (float) $hold->transfer->amount,
                        'currency' => strtoupper($hold->transfer->currency),
                        'status' => $hold->transfer->status,
                        'date' => $hold->transfer->created_at->toIso8601String(),
                        'description' => 'Transfer to Stripe Connect account',
                        'transfer_details' => [
                            'transfer_id' => $hold->transfer->id,
                            'stripe_transfer_id' => $hold->transfer->stripe_transfer_id,
                            'transfer_type' => $hold->transfer->transfer_type,
                            'transferred_at' => $hold->transferred_at?->toIso8601String(),
                        ],
                    ];
                }
            }

            // Sort all transactions by date (newest first)
            usort($transactions, function ($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });

            // Calculate summary
            $checkoutTotal = collect($transactions)
                ->where('transaction_type', 'checkout')
                ->where('status', 'succeeded')
                ->sum('amount');

            $transferTotal = collect($transactions)
                ->where('transaction_type', 'transfer')
                ->where('status', 'completed')
                ->sum('amount');

            return response()->json([
                'success' => true,
                'message' => 'Transaction history retrieved successfully.',
                'summary' => [
                    'total_checkout_amount' => (float) $checkoutTotal,
                    'total_transferred_amount' => (float) $transferTotal,
                    'pending_balance' => (float) ($checkoutTotal - $transferTotal),
                    'total_transactions' => count($transactions),
                ],
                'transactions' => $transactions,
                'pagination' => [
                    'current_page' => $holds->currentPage(),
                    'per_page' => $holds->perPage(),
                    'total' => $holds->total(),
                    'last_page' => $holds->lastPage(),
                    'from' => $holds->firstItem(),
                    'to' => $holds->lastItem(),
                    'has_more_pages' => $holds->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Transaction History Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transaction history.',
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
     * Get user's wallet balance summary.
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get all holds for this user
            $holds = PaymentHold::where('user_id', $user->id)->get();

            // Get all transfers to calculate actual transferred amounts
            $transfers = Transfer::where('user_id', $user->id)->get();

            // Calculate total received from checkouts
            $totalCheckoutAmount = $holds->sum('amount');

            // Calculate total actually transferred from wallet
            $totalTransferredFromWallet = $transfers->whereIn('status', ['completed', 'pending'])->sum('amount');

            // LOCKED AMOUNT: Money still in hold period (cannot be withdrawn yet)
            $lockedAmount = $holds
                ->where('status', 'holding')
                ->filter(function ($hold) {
                    return $hold->hold_end_at && $hold->hold_end_at->isFuture();
                })
                ->sum('amount');

            // READY FOR TRANSFER: Money that completed hold period (can be withdrawn)
            // Includes: status='ready_for_transfer' OR status='holding' with completed period
            $readyForTransferAmount = $holds
                ->where('status', 'ready_for_transfer')
                ->sum('amount');

            $holdingButReady = $holds
                ->where('status', 'holding')
                ->filter(function ($hold) {
                    return $hold->hold_end_at && $hold->hold_end_at->isPast();
                })
                ->sum('amount');

            $totalReadyForTransfer = $readyForTransferAmount + $holdingButReady;

            // TOTAL BALANCE: Money in your wallet (not yet transferred out)
            // Total Balance = Total Received - Total Transferred
            $totalBalance = $totalCheckoutAmount - $totalTransferredFromWallet;

            // Make sure total_balance matches locked + ready
            // (transferred holds have amount=0 in calculations above)
            $calculatedBalance = $lockedAmount + $totalReadyForTransfer;

            return response()->json([
                'success' => true,
                'message' => 'Balance summary retrieved successfully.',
                'summary' => [
                    'total_balance' => (float) $calculatedBalance,
                    'total_locked_amount' => (float) $lockedAmount,
                    'total_ready_for_transfer_amount' => (float) $totalReadyForTransfer,
                    'currency' => 'USD',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Balance Summary Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve balance summary.',
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
     * Calculate hold duration details for a payment hold.
     */
    protected function calculateHoldDuration(PaymentHold $hold): array
    {
        $holdDuration = [
            'hold_days' => $hold->hold_days,
            'hold_period_type' => $hold->hold_period_type,
            'hold_start_at' => $hold->hold_start_at?->toIso8601String(),
            'hold_end_at' => $hold->hold_end_at?->toIso8601String(),
            'days_elapsed' => null,
            'days_remaining' => null,
            'is_complete' => false,
        ];

        if ($hold->hold_start_at && $hold->hold_end_at) {
            $now = now();

            // Calculate days elapsed since hold started
            $holdDuration['days_elapsed'] = max(0, $hold->hold_start_at->diffInDays($now));

            // Calculate days remaining (can be negative if overdue)
            $daysRemaining = $now->diffInDays($hold->hold_end_at, false);
            $holdDuration['days_remaining'] = $daysRemaining >= 0 ? $daysRemaining : 0;

            // Check if hold period is complete
            $holdDuration['is_complete'] = $hold->hold_end_at->isPast();
        }

        return $holdDuration;
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
