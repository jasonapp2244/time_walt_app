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

            // Check if already fully transferred
            $remainingAmount = $hold->remaining_amount ?? $hold->amount;
            if ($hold->status === 'transferred' || $remainingAmount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payout already completed for this hold.',
                ], 400);
            }

            // Check if there's a pending transfer for this hold
            $pendingTransfer = Transfer::where('hold_id', $hold->id)
                ->whereIn('status', ['pending', 'processing'])
                ->first();

            if ($pendingTransfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payout request already exists for this hold.',
                    'data' => [
                        'transfer_id' => $pendingTransfer->id,
                        'status' => $pendingTransfer->status,
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
                ->sum(function ($hold) {
                    return $hold->remaining_amount ?? $hold->amount;
                });

            // READY FOR TRANSFER: Money that completed hold period (can be withdrawn)
            // Includes: status='ready_for_transfer' OR status='partial_transferred' OR status='holding' with completed period
            $readyForTransferAmount = $holds
                ->where(function ($query) {
                    $query->where('status', 'ready_for_transfer')
                        ->orWhere('status', 'partial_transferred')
                        ->orWhere(function ($q) {
                            $q->where('status', 'holding')
                                ->whereNotNull('hold_end_at')
                                ->where('hold_end_at', '<=', now());
                        });
                })
                ->sum(function ($hold) {
                    $remaining = $hold->remaining_amount ?? $hold->amount;

                    return $remaining > 0 ? $remaining : 0;
                });

            $totalReadyForTransfer = $readyForTransferAmount;

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
            // Include holds with remaining_amount > 0, regardless of status (as long as hold period is complete)
            $availableHolds = PaymentHold::where('user_id', $user->id)
                ->where(function ($query) {
                    // Status-based conditions
                    $query->where('status', 'ready_for_transfer')
                        ->orWhere('status', 'partial_transferred')
                        ->orWhere(function ($q) {
                            // Holding status but hold period completed
                            $q->where('status', 'holding')
                                ->whereNotNull('hold_end_at')
                                ->where('hold_end_at', '<=', now());
                        })
                        ->orWhere(function ($q) {
                            // Transferred status but still has remaining_amount > 0 (data inconsistency fix)
                            $q->where('status', 'transferred')
                                ->where('remaining_amount', '>', 0);
                        });
                })
                ->with('payment') // Eager load payment relationship
                ->orderBy('hold_end_at', 'asc')
                ->get()
                ->map(function ($hold) {
                    // Calculate and set remaining_amount if not set
                    if ($hold->remaining_amount === null) {
                        // Calculate remaining: amount - sum of completed/pending transfers
                        $transferredAmount = \App\Models\Transfer::where('hold_id', $hold->id)
                            ->whereIn('status', ['completed', 'pending'])
                            ->sum('amount');
                        $hold->remaining_amount = max(0, $hold->amount - $transferredAmount);
                        // Update the hold if remaining_amount was null
                        if ($hold->remaining_amount !== $hold->amount) {
                            $hold->update(['remaining_amount' => $hold->remaining_amount]);
                        }
                    }

                    // Fix status inconsistency: if status is 'transferred' but remaining_amount > 0, change to 'partial_transferred'
                    if ($hold->status === 'transferred' && $hold->remaining_amount > 0) {
                        $hold->update(['status' => 'partial_transferred']);
                        $hold->status = 'partial_transferred';
                    }

                    return $hold;
                })
                ->filter(function ($hold) {
                    // Ensure hold period is complete (if holding status)
                    if ($hold->status === 'holding') {
                        if (! $hold->hold_end_at || $hold->hold_end_at->isFuture()) {
                            return false; // Hold period not complete
                        }
                    }
                    // Filter by remaining_amount > 0
                    $remaining = (float) ($hold->remaining_amount ?? $hold->amount);

                    return $remaining > 0;
                });

            if ($availableHolds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No funds available for withdrawal. Hold period not completed yet.',
                ], 400);
            }

            // Calculate total available amount - ALWAYS use remaining_amount
            $totalAvailable = 0;
            $holdDetails = [];

            foreach ($availableHolds as $hold) {
                $remaining = (float) ($hold->remaining_amount ?? $hold->amount);
                $totalAvailable += $remaining;
                $holdDetails[] = [
                    'hold_id' => $hold->id,
                    'original_amount' => (float) $hold->amount,
                    'remaining_amount' => (float) ($hold->remaining_amount ?? $hold->amount),
                    'status' => $hold->status,
                ];
            }

            // Log for debugging
            Log::info('Withdrawal availability check', [
                'user_id' => $user->id,
                'requested_amount' => $requestedAmount,
                'total_available' => $totalAvailable,
                'holds_count' => $availableHolds->count(),
                'hold_details' => $holdDetails,
            ]);

            if ($requestedAmount > $totalAvailable) {
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient available funds. Available: \${$totalAvailable}, Requested: \${$requestedAmount}",
                    'data' => [
                        'available_amount' => (float) $totalAvailable,
                        'requested_amount' => (float) $requestedAmount,
                        'holds_count' => $availableHolds->count(),
                    ],
                ], 400);
            }

            // Check if user has Stripe Connect account
            $connectAccount = StripeConnectAccount::where('user_id', $user->id)->first();

            if (! $connectAccount) {
                return response()->json([
                    'success' => false,
                    'message' => 'No bank account found. Please add your bank details first.',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Re-fetch holds with pessimistic lock to prevent concurrent over-withdrawal
                $lockedHoldIds = $availableHolds->pluck('id')->toArray();
                $lockedHolds = PaymentHold::whereIn('id', $lockedHoldIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Re-validate available amounts with locked data
                $totalAvailableLocked = 0;
                foreach ($lockedHolds as $hold) {
                    $remaining = (float) ($hold->remaining_amount ?? $hold->amount);
                    if ($remaining > 0 && $hold->status !== 'transferred') {
                        $totalAvailableLocked += $remaining;
                    }
                }

                if ($requestedAmount > $totalAvailableLocked) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient available funds. Available: \${$totalAvailableLocked}, Requested: \${$requestedAmount}",
                        'data' => [
                            'available_amount' => (float) $totalAvailableLocked,
                            'requested_amount' => (float) $requestedAmount,
                        ],
                    ], 400);
                }

                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $remainingAmount = $requestedAmount;
                $processedHolds = [];

                // Process holds until we've transferred the requested amount
                foreach ($availableHolds as $hold) {
                    // Use the locked version for accurate remaining_amount
                    $hold = $lockedHolds->get($hold->id) ?? $hold;
                    if ($remainingAmount <= 0) {
                        break;
                    }

                    // Use remaining_amount - it should already be set by the map function above (lines 364-376)
                    // Get available amount directly without recalculating
                    $availableInHold = (float) ($hold->remaining_amount ?? $hold->amount);

                    // Skip if no amount available in this hold
                    if ($availableInHold <= 0) {
                        continue;
                    }

                    $amountFromThisHold = min($remainingAmount, $availableInHold);

                    // Get currency from payment (ensure payment is loaded)
                    $payment = $hold->payment;
                    if (! $payment) {
                        Log::warning('Payment not found for hold', [
                            'hold_id' => $hold->id,
                            'user_id' => $user->id,
                        ]);

                        continue; // Skip this hold if payment is missing
                    }
                    $currency = strtolower($payment->currency);

                    // Create Stripe transfer for this amount
                    $transfer = \Stripe\Transfer::create([
                        'amount' => (int) round($amountFromThisHold * 100),
                        'currency' => $currency,
                        'destination' => $connectAccount->connect_account_id,
                        'description' => "Withdrawal from hold #{$hold->id}",
                        'metadata' => [
                            'hold_id' => $hold->id,
                            'user_id' => $user->id,
                            'payment_id' => $payment ? $payment->id : null,
                            'withdrawal_type' => $amountFromThisHold < $availableInHold ? 'partial' : 'full',
                        ],
                    ]);

                    // Determine transfer status - Stripe transfers are usually instant
                    $transferStatus = 'pending';
                    $transferredAt = null;

                    // Check if transfer succeeded immediately (most Stripe transfers are instant)
                    if (isset($transfer->id) && ! isset($transfer->failure_message)) {
                        $transferStatus = 'completed';
                        $transferredAt = now();
                    } elseif (isset($transfer->failure_message)) {
                        $transferStatus = 'failed';
                    }

                    // Create transfer record
                    $transferRecord = Transfer::create([
                        'hold_id' => $hold->id,
                        'user_id' => $user->id,
                        'stripe_transfer_id' => $transfer->id,
                        'stripe_connect_account_id' => $connectAccount->connect_account_id,
                        'amount' => $amountFromThisHold,
                        'currency' => $currency,
                        'status' => $transferStatus,
                        'transferred_at' => $transferredAt,
                        'transfer_type' => $amountFromThisHold < $availableInHold ? 'user_requested_partial' : 'user_requested',
                        'admin_id' => null,
                        'stripe_data' => $transfer->toArray(),
                        'failure_reason' => $transfer->failure_message ?? null,
                    ]);

                    // Calculate new remaining amount
                    $newRemainingAmount = $availableInHold - $amountFromThisHold;

                    // Update hold with remaining_amount and appropriate status
                    // Only update if transfer is completed, otherwise leave status as is
                    if ($transferStatus === 'completed') {
                        $hold->update([
                            'remaining_amount' => $newRemainingAmount,
                            'status' => $newRemainingAmount <= 0 ? 'transferred' : 'partial_transferred',
                            'transferred_at' => $newRemainingAmount <= 0 ? now() : $hold->transferred_at,
                        ]);
                    } else {
                        // Transfer is pending or failed - just update remaining_amount
                        $hold->update([
                            'remaining_amount' => $newRemainingAmount,
                        ]);
                    }

                    $processedHolds[] = [
                        'hold_id' => $hold->id,
                        'amount' => (float) $amountFromThisHold,
                        'transfer_id' => $transferRecord->id,
                        'status' => $transferStatus,
                    ];

                    $remainingAmount -= $amountFromThisHold;
                }

                // Check if any holds were processed
                if (empty($processedHolds)) {
                    DB::rollBack();
                    Log::warning('No holds processed for withdrawal', [
                        'user_id' => $user->id,
                        'requested_amount' => $requestedAmount,
                        'available_holds_count' => $availableHolds->count(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'No holds could be processed. Please check your available balance.',
                    ], 400);
                }

                // Check if full amount was processed
                $totalProcessed = collect($processedHolds)->sum('amount');
                if ($totalProcessed < $requestedAmount) {
                    Log::warning('Partial amount processed', [
                        'user_id' => $user->id,
                        'requested_amount' => $requestedAmount,
                        'processed_amount' => $totalProcessed,
                    ]);
                }

                DB::commit();

                // Send email notifications based on transfer status
                $userSettings = UserNotificationSetting::where('user_id', $user->id)->first();
                $shouldSendEmail = ! $userSettings || ($userSettings->transaction_alert && $userSettings->email_alert);

                if ($shouldSendEmail && ! empty($processedHolds)) {
                    // Get all transfer records
                    $transferIds = collect($processedHolds)->pluck('transfer_id')->toArray();
                    $transfers = Transfer::whereIn('id', $transferIds)
                        ->with(['hold.payment', 'user'])
                        ->get();

                    // Check if all transfers are completed immediately
                    $completedTransfers = $transfers->where('status', 'completed');
                    $pendingTransfers = $transfers->where('status', 'pending');

                    if ($completedTransfers->count() === $transfers->count()) {
                        // All transfers completed - send success email
                        if ($transfers->count() > 1) {
                            \App\Jobs\SendTransferCompletedSummaryNotification::dispatch($user, $transfers, $totalProcessed);
                        } else {
                            \App\Jobs\SendTransferCompletedNotification::dispatch($transfers->first());
                        }
                    } elseif ($pendingTransfers->count() > 0) {
                        // Some or all transfers are pending - send withdrawal request email
                        \App\Jobs\SendWithdrawalSummaryNotification::dispatch($user, $transfers, $requestedAmount, $totalProcessed);
                    }
                }

                Log::info('Withdrawal completed', [
                    'user_id' => $user->id,
                    'requested_amount' => $requestedAmount,
                    'processed_amount' => $totalProcessed,
                    'processed_holds' => count($processedHolds),
                ]);

                // Mark transfers as email pending (will be updated by job)
                if ($shouldSendEmail && ! empty($processedHolds)) {
                    $transferIds = collect($processedHolds)->pluck('transfer_id')->toArray();
                    Transfer::whereIn('id', $transferIds)->update(['email_status' => 'pending']);
                }

                // Get transfer details for response
                $transferDetails = [];
                $allCompleted = true;
                $anyFailed = false;

                foreach ($processedHolds as $processedHold) {
                    $transfer = Transfer::find($processedHold['transfer_id']);
                    if ($transfer) {
                        $transferDetails[] = [
                            'hold_id' => $processedHold['hold_id'],
                            'transfer_id' => $transfer->id,
                            'stripe_transfer_id' => $transfer->stripe_transfer_id,
                            'amount' => (float) $transfer->amount,
                            'currency' => strtoupper($transfer->currency),
                            'status' => $transfer->status,
                            'transferred_at' => $transfer->transferred_at?->toIso8601String(),
                            'email_status' => $transfer->email_status ?? 'pending',
                            'email_sent_at' => $transfer->email_sent_at?->toIso8601String(),
                        ];

                        if ($transfer->status !== 'completed') {
                            $allCompleted = false;
                        }
                        if ($transfer->status === 'failed') {
                            $anyFailed = true;
                        }
                    }
                }

                // Determine overall status and message
                $overallStatus = $allCompleted ? 'completed' : ($anyFailed ? 'partial_failed' : 'pending');
                $message = $allCompleted
                    ? "Withdrawal of \${$totalProcessed} completed successfully."
                    : "Withdrawal request for \${$requestedAmount} submitted successfully. Transfers are being processed.";

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'requested_amount' => (float) $requestedAmount,
                        'processed_amount' => (float) $totalProcessed,
                        'total_transfers' => count($processedHolds),
                        'holds_processed' => $processedHolds,
                        'transfers' => $transferDetails,
                        'status' => $overallStatus,
                        'all_completed' => $allCompleted,
                        'summary' => [
                            'requested' => (float) $requestedAmount,
                            'processed' => (float) $totalProcessed,
                            'difference' => (float) ($requestedAmount - $totalProcessed),
                        ],
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe withdrawal failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id ?? null,
                'requested_amount' => $request->input('amount'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process withdrawal request. Please try again later or contact support.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user_id' => $request->user()->id ?? null,
                'requested_amount' => $request->input('amount'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your withdrawal. Please try again later.',
                'error' => config('app.debug') ? $e->getMessage() : null,
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
        } elseif ($hold->hold_end_at) {
            $holdDuration['is_complete'] = $hold->hold_end_at->isPast();
        }

        return $holdDuration;
    }

    /**
     * Check if user can request payout for this hold.
     */
    protected function canRequestPayout(PaymentHold $hold): bool
    {
        // Check remaining amount
        $remainingAmount = $hold->remaining_amount ?? $hold->amount;
        if ($remainingAmount <= 0) {
            return false;
        }

        // Already fully transferred
        if ($hold->status === 'transferred') {
            return false;
        }

        // Check if there's a pending transfer
        $pendingTransfer = Transfer::where('hold_id', $hold->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($pendingTransfer) {
            return false;
        }

        // Check if hold period is complete
        if ($hold->hold_end_at && $hold->hold_end_at->isFuture()) {
            return false;
        }

        // If status is ready_for_transfer, partial_transferred, or holding (and period complete)
        return $hold->status === 'ready_for_transfer' ||
               $hold->status === 'partial_transferred' ||
               ($hold->status === 'holding' && $hold->hold_end_at && $hold->hold_end_at->isPast());
    }
}
