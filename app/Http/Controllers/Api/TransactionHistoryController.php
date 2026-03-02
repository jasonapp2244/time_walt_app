<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentHold;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TransactionHistoryController extends Controller
{
    /**
     * Get all transactions organized by categories.
     */
    public function all(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get all payment holds with their relationships
            $allHolds = PaymentHold::where('user_id', $user->id)
                ->with(['payment', 'transfer'])
                ->orderBy('created_at', 'desc')
                ->get();

            // 1. CHECKOUT - Successful payments
            $checkouts = [];
            foreach ($allHolds as $hold) {
                if ($hold->payment && $hold->payment->status === 'succeeded') {
                    $checkouts[] = $this->formatCheckoutTransaction($hold);
                }
            }

            // 2. WITHDRAW - Group transfers by withdrawal request (same created_at within 5 seconds)
            $withdraws = [];
            $allTransfers = Transfer::where('user_id', $user->id)
                ->whereIn('status', ['pending', 'completed'])
                ->with(['hold.payment'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Group transfers by withdrawal request (created within 5 seconds of each other)
            $groupedTransfers = [];
            $currentGroup = [];
            $lastCreatedAt = null;

            foreach ($allTransfers as $transfer) {
                if ($lastCreatedAt === null || abs($transfer->created_at->diffInSeconds($lastCreatedAt)) <= 5) {
                    // Same withdrawal request
                    $currentGroup[] = $transfer;
                } else {
                    // New withdrawal request
                    if (! empty($currentGroup)) {
                        $groupedTransfers[] = $currentGroup;
                    }
                    $currentGroup = [$transfer];
                }
                $lastCreatedAt = $transfer->created_at;
            }

            // Add the last group
            if (! empty($currentGroup)) {
                $groupedTransfers[] = $currentGroup;
            }

            // Format grouped withdrawals
            foreach ($groupedTransfers as $transferGroup) {
                $withdraws[] = $this->formatGroupedWithdrawTransaction($transferGroup);
            }

            // 3. HOLD AMOUNTS - Money currently locked in holding period
            $holdAmounts = [];
            $lockedHolds = $allHolds->filter(function ($hold) {
                return $hold->status === 'holding' &&
                       $hold->hold_end_at &&
                       $hold->hold_end_at->isFuture();
            });
            foreach ($lockedHolds as $hold) {
                $holdAmounts[] = $this->formatHoldAmountTransaction($hold);
            }

            // 4. READY FOR TRANSFER - Money that can be withdrawn (using remaining_amount)
            $readyForTransfer = [];
            $readyHolds = $allHolds->filter(function ($hold) {
                $remainingAmount = $hold->remaining_amount ?? $hold->amount;

                // Must have remaining amount > 0
                if ($remainingAmount <= 0) {
                    return false;
                }

                // Exclude transferred status (fully transferred)
                if ($hold->status === 'transferred') {
                    return false;
                }

                // Check status and hold period
                $isReady = (
                    $hold->status === 'ready_for_transfer' ||
                    $hold->status === 'partial_transferred' ||
                    ($hold->status === 'holding' && $hold->hold_end_at && $hold->hold_end_at->isPast())
                );

                return $isReady;
            });
            foreach ($readyHolds as $hold) {
                $readyForTransfer[] = $this->formatReadyForTransferTransaction($hold);
            }

            // 5. TRANSFERRED - Already transferred funds (from Transfer table with status = completed)
            $transferred = [];
            $completedTransfers = Transfer::where('user_id', $user->id)
                ->where('status', 'completed')
                ->with(['hold.payment'])
                ->orderBy('transferred_at', 'desc')
                ->get();

            foreach ($completedTransfers as $transfer) {
                if ($transfer->hold) {
                    $transferred[] = $this->formatTransferredTransactionFromTransfer($transfer);
                }
            }

            // Calculate summary
            $checkoutTotal = collect($checkouts)->sum('amount');
            $withdrawTotal = collect($withdraws)->sum('amount');
            $holdAmountTotal = collect($holdAmounts)->sum(function ($item) {
                return $item['remaining_amount'] ?? $item['amount'] ?? 0;
            });
            $readyTotal = collect($readyForTransfer)->sum(function ($item) {
                return $item['remaining_amount'] ?? $item['amount'] ?? 0;
            });

            // Get total transferred amount from Transfer table with status 'completed'
            $transferredTotal = Transfer::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('amount');

            // Calculate current balance (what's left in account = hold + ready)
            $currentBalance = $holdAmountTotal + $readyTotal;

            return response()->json([
                'success' => true,
                'message' => 'All transactions retrieved successfully.',
                'summary' => [
                    'total_checkout_amount' => (float) $checkoutTotal, // Total received from all checkouts
                    'current_balance' => (float) $currentBalance, // hold + ready (what's left in account)
                    'total_locked_amount' => (float) $holdAmountTotal, // Same as total_hold_amount
                    'available_balance' => (float) $readyTotal, // Same as total_ready_amount
                    // 'total_withdraw_amount' => (float) $withdrawTotal, // Same as total_transferred_amount
                    'total_hold_amount' => (float) $holdAmountTotal, // Locked (still in holding period)
                    'total_ready_amount' => (float) $readyTotal, // Available for withdrawal
                    // 'total_transferred_amount' => (float) $transferredTotal, // Already withdrawn
                    'currency' => 'USD',
                ],
                'data' => [
                    'checkout' => $checkouts,
                    'withdraw' => $withdraws,
                    'hold_amount' => $holdAmounts,
                    'ready_for_transfer' => $readyForTransfer,
                    'transferred' => $transferred,
                ],
                'counts' => [
                    'checkout_count' => count($checkouts),
                    'withdraw_count' => count($withdraws),
                    'hold_amount_count' => count($holdAmounts),
                    'ready_for_transfer_count' => count($readyForTransfer),
                    'transferred_count' => count($transferred),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get All Transactions Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve transactions.',
            ], 500);
        }
    }

    /**
     * Get checkout transactions only with pagination.
     */
    public function checkouts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $this->getPerPage($request);

            $holds = PaymentHold::where('user_id', $user->id)
                ->whereHas('payment', function ($query) {
                    $query->where('status', 'succeeded');
                })
                ->with('payment')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            $checkouts = [];
            foreach ($holds as $hold) {
                $checkouts[] = $this->formatCheckoutTransaction($hold);
            }

            // Calculate comprehensive summary
            $allCheckoutHolds = PaymentHold::where('user_id', $user->id)
                ->whereHas('payment', function ($query) {
                    $query->where('status', 'succeeded');
                })
                ->get();

            $totalAmount = $allCheckoutHolds->sum('amount');
            $averageAmount = $allCheckoutHolds->count() > 0 ? $allCheckoutHolds->avg('amount') : 0;
            $largestAmount = $allCheckoutHolds->max('amount');
            $smallestAmount = $allCheckoutHolds->min('amount');

            return response()->json([
                'success' => true,
                'message' => 'Checkout transactions retrieved successfully.',
                'summary' => [
                    'total_checkout_amount' => (float) $totalAmount,
                    'total_checkouts' => $holds->total(),
                    'average_checkout_amount' => (float) $averageAmount,
                    'largest_checkout_amount' => (float) $largestAmount,
                    'smallest_checkout_amount' => (float) $smallestAmount,
                    'currency' => 'USD',
                ],
                'data' => $checkouts,
                'pagination' => $this->formatPagination($holds),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Checkout Transactions Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve checkout transactions.',
            ], 500);
        }
    }

    /**
     * Get withdraw/transfer transactions only with pagination.
     */
    public function withdraws(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $this->getPerPage($request);

            // Get all transfers and group by withdrawal request
            $allTransfers = Transfer::where('user_id', $user->id)
                ->with(['hold.payment'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Group transfers by withdrawal request (created within 5 seconds of each other)
            $groupedTransfers = [];
            $currentGroup = [];
            $lastCreatedAt = null;

            foreach ($allTransfers as $transfer) {
                if ($transfer->hold) {
                    if ($lastCreatedAt === null || abs($transfer->created_at->diffInSeconds($lastCreatedAt)) <= 5) {
                        // Same withdrawal request
                        $currentGroup[] = $transfer;
                    } else {
                        // New withdrawal request
                        if (! empty($currentGroup)) {
                            $groupedTransfers[] = $currentGroup;
                        }
                        $currentGroup = [$transfer];
                    }
                    $lastCreatedAt = $transfer->created_at;
                }
            }

            // Add the last group
            if (! empty($currentGroup)) {
                $groupedTransfers[] = $currentGroup;
            }

            // Paginate grouped withdrawals manually
            $currentPage = $request->input('page', 1);
            $perPage = $this->getPerPage($request);
            $total = count($groupedTransfers);
            $items = collect($groupedTransfers)->slice(($currentPage - 1) * $perPage, $perPage)->values();

            // Create paginator manually
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $withdraws = [];
            foreach ($items as $transferGroup) {
                $withdraws[] = $this->formatGroupedWithdrawTransaction($transferGroup);
            }

            // Calculate comprehensive summary
            $allTransfers = Transfer::where('user_id', $user->id)->get();

            $totalPendingAmount = $allTransfers->where('status', 'pending')->sum('amount');
            $totalCompletedAmount = $allTransfers->where('status', 'completed')->sum('amount');
            $totalFailedAmount = $allTransfers->where('status', 'failed')->sum('amount');
            $totalWithdrawnAmount = $totalPendingAmount + $totalCompletedAmount;

            $pendingCount = $allTransfers->where('status', 'pending')->count();
            $completedCount = $allTransfers->where('status', 'completed')->count();
            $failedCount = $allTransfers->where('status', 'failed')->count();

            return response()->json([
                'success' => true,
                'message' => 'Withdraw transactions retrieved successfully.',
                'summary' => [
                    'total_withdrawn_amount' => (float) $totalWithdrawnAmount,
                    'total_pending_amount' => (float) $totalPendingAmount,
                    'total_completed_amount' => (float) $totalCompletedAmount,
                    'total_failed_amount' => (float) $totalFailedAmount,
                    'total_withdraw_requests' => $total,
                    'pending_withdraws' => $pendingCount,
                    'completed_withdraws' => $completedCount,
                    'failed_withdraws' => $failedCount,
                    'currency' => 'USD',
                ],
                'data' => $withdraws,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_more_pages' => $paginator->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Withdraw Transactions Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve withdraw transactions.',
            ], 500);
        }
    }

    /**
     * Get hold amount list (money in holding period) with pagination.
     */
    public function holdAmounts(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $this->getPerPage($request);

            // Only show holds currently in holding period (status = holding AND hold_end_at > now)
            $holds = PaymentHold::where('user_id', $user->id)
                ->where('status', 'holding')
                ->whereNotNull('hold_end_at')
                ->where('hold_end_at', '>', now())
                ->with('payment')
                ->orderBy('hold_end_at', 'asc')
                ->paginate($perPage);

            $holdAmounts = [];
            foreach ($holds as $hold) {
                $holdAmounts[] = $this->formatHoldAmountTransaction($hold);
            }

            // Calculate comprehensive summary
            $allHoldingHolds = PaymentHold::where('user_id', $user->id)
                ->where('status', 'holding')
                ->whereNotNull('hold_end_at')
                ->where('hold_end_at', '>', now())
                ->get();

            $totalLockedAmount = $allHoldingHolds->sum(function ($hold) {
                return $hold->remaining_amount ?? $hold->amount;
            });

            $totalOriginalAmount = $allHoldingHolds->sum('amount');
            $averageHoldDays = $allHoldingHolds->avg('hold_days');

            // Calculate days until release
            $now = now();
            $earliestRelease = $allHoldingHolds->min(function ($hold) use ($now) {
                return $hold->hold_end_at ? $now->diffInDays($hold->hold_end_at, false) : null;
            });
            $latestRelease = $allHoldingHolds->max(function ($hold) use ($now) {
                return $hold->hold_end_at ? $now->diffInDays($hold->hold_end_at, false) : null;
            });

            return response()->json([
                'success' => true,
                'message' => 'Hold amounts retrieved successfully.',
                'summary' => [
                    'total_locked_amount' => (float) $totalLockedAmount,
                    'total_original_amount' => (float) $totalOriginalAmount,
                    'total_holds' => $holds->total(),
                    'average_hold_days' => $averageHoldDays ? (int) $averageHoldDays : null,
                    'earliest_release_days' => $earliestRelease !== null ? (int) $earliestRelease : null,
                    'latest_release_days' => $latestRelease !== null ? (int) $latestRelease : null,
                    'currency' => 'USD',
                ],
                'data' => $holdAmounts,
                'pagination' => $this->formatPagination($holds),
            ]);
        } catch (\Exception $e) {
            Log::error('Get Hold Amounts Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve hold amounts.',
            ], 500);
        }
    }

    /**
     * Get ready for transfer amount list (money ready to withdraw) with pagination.
     */
    public function readyForTransfer(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $perPage = $this->getPerPage($request);

            // Get holds that are ready for transfer (excluding transferred status)
            $holds = PaymentHold::where('user_id', $user->id)
                ->where(function ($query) {
                    $query->where('status', 'ready_for_transfer')
                        ->orWhere('status', 'partial_transferred')
                        ->orWhere(function ($q) {
                            $q->where('status', 'holding')
                                ->whereNotNull('hold_end_at')
                                ->where('hold_end_at', '<=', now());
                        });
                })
                ->where('status', '!=', 'transferred') // Exclude fully transferred
                ->with('payment')
                ->orderBy('hold_end_at', 'asc')
                ->get()
                ->filter(function ($hold) {
                    // Filter by remaining_amount > 0
                    $remaining = $hold->remaining_amount ?? $hold->amount;

                    return $remaining > 0;
                });

            // Paginate manually after filtering
            $currentPage = $request->input('page', 1);
            $perPage = $this->getPerPage($request);
            $total = $holds->count();
            $items = $holds->slice(($currentPage - 1) * $perPage, $perPage)->values();

            // Create paginator manually
            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $currentPage,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $readyAmounts = [];
            foreach ($items as $hold) {
                $readyAmounts[] = $this->formatReadyForTransferTransaction($hold);
            }

            // Calculate comprehensive summary
            $totalReadyAmount = $holds->sum(function ($hold) {
                $remaining = $hold->remaining_amount ?? $hold->amount;

                return $remaining > 0 ? $remaining : 0;
            });

            $totalOriginalAmount = $holds->sum('amount');
            $totalAlreadyWithdrawn = $totalOriginalAmount - $totalReadyAmount;

            // Count by status
            $readyForTransferCount = $holds->where('status', 'ready_for_transfer')->count();
            $partialTransferredCount = $holds->where('status', 'partial_transferred')->count();
            $expiredHoldingCount = $holds->where('status', 'holding')->count();

            // Calculate average remaining percentage
            $averageRemainingPercentage = $holds->count() > 0
                ? $holds->avg(function ($hold) {
                    $remaining = $hold->remaining_amount ?? $hold->amount;
                    $original = $hold->amount;

                    return $original > 0 ? ($remaining / $original) * 100 : 0;
                })
                : 0;

            return response()->json([
                'success' => true,
                'message' => 'Ready for transfer amounts retrieved successfully.',
                'summary' => [
                    'total_ready_amount' => (float) $totalReadyAmount,
                    'total_original_amount' => (float) $totalOriginalAmount,
                    'total_already_withdrawn' => (float) $totalAlreadyWithdrawn,
                    'total_ready_holds' => $total,
                    'ready_for_transfer_count' => $readyForTransferCount,
                    'partial_transferred_count' => $partialTransferredCount,
                    'expired_holding_count' => $expiredHoldingCount,
                    'average_remaining_percentage' => round((float) $averageRemainingPercentage, 2),
                    'currency' => 'USD',
                ],
                'data' => $readyAmounts,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_more_pages' => $paginator->hasMorePages(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Ready For Transfer Amounts Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve ready for transfer amounts.',
            ], 500);
        }
    }

    /**
     * Format checkout transaction.
     */
    protected function formatCheckoutTransaction(PaymentHold $hold): array
    {
        return [
            'transaction_type' => 'checkout',
            'transaction_id' => "CHK-{$hold->id}",
            'hold_id' => $hold->id,
            'title' => $hold->title ?? 'Payment Received',
            'amount' => (float) $hold->amount,
            'currency' => $hold->payment ? strtoupper($hold->payment->currency) : 'USD',
            'status' => $hold->payment ? $hold->payment->status : 'unknown',
            'date' => $hold->created_at->toIso8601String(),
            'description' => 'Checkout payment received',
            'payment_details' => [
                'payment_id' => $hold->payment?->id,
                'payment_intent_id' => $hold->payment?->payment_intent_id,
                'hold_status' => $hold->status,
                'paid_at' => $hold->payment?->paid_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Format withdraw/transfer transaction.
     */
    protected function formatWithdrawTransaction(PaymentHold $hold): array
    {
        return [
            'transaction_type' => 'withdraw',
            'transaction_id' => "WDR-{$hold->transfer->id}",
            'hold_id' => $hold->id,
            'title' => $hold->title ?? 'Withdrawal Request',
            'amount' => (float) $hold->transfer->amount,
            'currency' => strtoupper($hold->transfer->currency),
            'status' => $hold->transfer->status,
            'date' => $hold->transfer->created_at->toIso8601String(),
            'description' => 'Withdrawal to Stripe Connect account',
            'transfer_details' => [
                'transfer_id' => $hold->transfer->id,
                'stripe_transfer_id' => $hold->transfer->stripe_transfer_id,
                'transfer_type' => $hold->transfer->transfer_type,
                'transferred_at' => $hold->transferred_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Format withdraw transaction from Transfer model.
     */
    protected function formatWithdrawTransactionFromTransfer(Transfer $transfer): array
    {
        $hold = $transfer->hold;

        $result = [
            'transaction_type' => 'withdraw',
            'transaction_id' => "WDR-{$transfer->id}",
            'hold_id' => $hold?->id,
            'title' => $hold?->title ?? 'Withdrawal Request',
            'amount' => (float) $transfer->amount,
            'currency' => strtoupper($transfer->currency),
            'status' => $transfer->status,
            'date' => $transfer->created_at->toIso8601String(),
            'description' => 'Withdrawal to Stripe Connect account',
            'transfer_details' => [
                'transfer_id' => $transfer->id,
                'stripe_transfer_id' => $transfer->stripe_transfer_id,
                'transfer_type' => $transfer->transfer_type,
                'transferred_at' => $transfer->transferred_at?->toIso8601String(),
                'failure_reason' => $transfer->failure_reason,
            ],
        ];

        // Add hold details if available (similar to email breakdown)
        if ($hold) {
            $result['hold_details'] = [
                'hold_id' => $hold->id,
                'original_amount' => (float) $hold->amount,
                'remaining_amount' => (float) ($hold->remaining_amount ?? $hold->amount),
                'hold_status' => $hold->status,
                'payment_id' => $hold->payment?->id,
                'payment_intent_id' => $hold->payment?->payment_intent_id,
            ];
        }

        return $result;
    }

    /**
     * Format grouped withdraw transaction (multiple transfers from one withdrawal request).
     */
    protected function formatGroupedWithdrawTransaction(array $transfers): array
    {
        if (empty($transfers)) {
            return [];
        }

        $firstTransfer = $transfers[0];
        $totalAmount = collect($transfers)->sum('amount');
        $allStatuses = collect($transfers)->pluck('status')->unique()->values()->toArray();

        // Determine overall status
        $overallStatus = 'pending';
        if (in_array('completed', $allStatuses) && ! in_array('pending', $allStatuses)) {
            $overallStatus = 'completed';
        } elseif (in_array('failed', $allStatuses)) {
            $overallStatus = 'failed';
        }

        // Get title from first transfer's hold
        $firstHoldTitle = $firstTransfer->hold?->title ?? 'Withdrawal Request';

        $transferDetails = [];
        foreach ($transfers as $index => $transfer) {
            $hold = $transfer->hold;
            $transferDetails[] = [
                'transfer_number' => $index + 1,
                'transfer_id' => $transfer->id,
                'hold_id' => $hold?->id,
                'amount' => (float) $transfer->amount,
                'currency' => strtoupper($transfer->currency),
                'status' => $transfer->status,
                'stripe_transfer_id' => $transfer->stripe_transfer_id,
                'transferred_at' => $transfer->transferred_at?->toIso8601String(),
                'failure_reason' => $transfer->failure_reason,
                'hold_details' => $hold ? [
                    'hold_id' => $hold->id,
                    'original_amount' => (float) $hold->amount,
                    'remaining_amount' => (float) ($hold->remaining_amount ?? $hold->amount),
                    'hold_status' => $hold->status,
                ] : null,
            ];
        }

        return [
            'transaction_type' => 'withdraw',
            'transaction_id' => "WDR-GROUP-{$firstTransfer->id}",
            'withdrawal_request_id' => $firstTransfer->id,
            'title' => $firstHoldTitle,
            'total_amount' => (float) $totalAmount,
            'currency' => strtoupper($firstTransfer->currency),
            'status' => $overallStatus,
            'date' => $firstTransfer->created_at->toIso8601String(),
            'description' => 'Withdrawal request with multiple transfers',
            'transfers_count' => count($transfers),
            'transfers' => $transferDetails,
        ];
    }

    /**
     * Format hold amount transaction.
     */
    protected function formatHoldAmountTransaction(PaymentHold $hold): array
    {
        $now = now();
        $daysRemaining = $hold->hold_end_at ? max(0, $now->diffInDays($hold->hold_end_at, false)) : 0;
        $daysElapsed = $hold->hold_start_at ? max(0, $hold->hold_start_at->diffInDays($now)) : 0;
        $remainingAmount = $hold->remaining_amount ?? $hold->amount;

        return [
            'hold_id' => $hold->id,
            'title' => $hold->title ?? 'Amount on Hold',
            'original_amount' => (float) $hold->amount,
            'remaining_amount' => (float) $remainingAmount,
            'amount' => (float) $remainingAmount,
            'currency' => $hold->payment ? strtoupper($hold->payment->currency) : 'USD',
            'status' => $hold->status,
            'hold_period' => [
                'hold_days' => $hold->hold_days,
                'hold_period_type' => $hold->hold_period_type,
                'hold_start_at' => $hold->hold_start_at?->toIso8601String(),
                'hold_end_at' => $hold->hold_end_at?->toIso8601String(),
                'days_elapsed' => $daysElapsed,
                'days_remaining' => $daysRemaining,
            ],
            'payment_details' => [
                'payment_id' => $hold->payment?->id,
                'payment_intent_id' => $hold->payment?->payment_intent_id,
                'paid_at' => $hold->payment?->paid_at?->toIso8601String(),
            ],
            'created_at' => $hold->created_at->toIso8601String(),
        ];
    }

    /**
     * Format ready for transfer transaction.
     */
    protected function formatReadyForTransferTransaction(PaymentHold $hold): array
    {
        $isCompleted = $hold->hold_end_at && $hold->hold_end_at->isPast();
        $remainingAmount = $hold->remaining_amount ?? $hold->amount;
        $originalAmount = $hold->amount;
        $alreadyWithdrawn = $originalAmount - $remainingAmount;

        return [
            'hold_id' => $hold->id,
            'title' => $hold->title ?? 'Ready for Withdrawal',
            'original_amount' => (float) $originalAmount,
            'remaining_amount' => (float) $remainingAmount,
            'already_withdrawn' => (float) $alreadyWithdrawn,
            'amount' => (float) $remainingAmount,
            'currency' => $hold->payment ? strtoupper($hold->payment->currency) : 'USD',
            'status' => $hold->status,
            'can_withdraw' => true,
            'hold_period' => [
                'hold_days' => $hold->hold_days,
                'hold_end_at' => $hold->hold_end_at?->toIso8601String(),
                'is_completed' => $isCompleted,
                'completed_at' => $isCompleted ? $hold->hold_end_at?->toIso8601String() : null,
            ],
            'payment_details' => [
                'payment_id' => $hold->payment?->id,
                'payment_intent_id' => $hold->payment?->payment_intent_id,
                'paid_at' => $hold->payment?->paid_at?->toIso8601String(),
            ],
            'ready_at' => $hold->ready_at?->toIso8601String(),
            'created_at' => $hold->created_at->toIso8601String(),
        ];
    }

    /**
     * Format transferred transaction from Transfer model.
     */
    protected function formatTransferredTransactionFromTransfer(Transfer $transfer): array
    {
        $hold = $transfer->hold;

        return [
            'transfer_id' => $transfer->id,
            'hold_id' => $hold->id,
            'title' => $hold->title ?? 'Transfer Completed',
            'amount' => (float) $transfer->amount,
            'currency' => strtoupper($transfer->currency),
            'status' => $transfer->status,
            'checkout_date' => $hold->created_at->toIso8601String(),
            'transferred_date' => $transfer->transferred_at?->toIso8601String(),
            'transfer_details' => [
                'stripe_transfer_id' => $transfer->stripe_transfer_id,
                'transfer_type' => $transfer->transfer_type,
                'stripe_connect_account_id' => $transfer->stripe_connect_account_id,
                'transferred_at' => $transfer->transferred_at?->toIso8601String(),
            ],
            'payment_details' => [
                'payment_id' => $hold->payment?->id,
                'payment_intent_id' => $hold->payment?->payment_intent_id,
                'paid_at' => $hold->payment?->paid_at?->toIso8601String(),
            ],
            'created_at' => $transfer->created_at->toIso8601String(),
        ];
    }

    /**
     * Get per page value from request.
     */
    protected function getPerPage(Request $request): int
    {
        $perPage = $request->input('per_page', 15);

        return min(max((int) $perPage, 1), 100); // Between 1 and 100
    }

    /**
     * Format pagination response.
     */
    protected function formatPagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }
}
