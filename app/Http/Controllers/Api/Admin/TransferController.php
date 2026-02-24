<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TransferRequest;
use App\Models\PaymentHold;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TransferController extends Controller
{
    public function __construct(
        protected StripeService $stripeService
    ) {}

    /**
     * Manually trigger transfer for a payment hold.
     */
    public function transfer(TransferRequest $request, int $holdId): JsonResponse
    {
        try {
            // Check if user is admin
            $user = $request->user();
            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.',
                ], 403);
            }

            // Find payment hold
            $hold = PaymentHold::find($holdId);

            if (! $hold) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment hold not found.',
                ], 404);
            }

            // Validate hold status
            if ($hold->status === 'transferred') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transfer already completed for this hold.',
                ], 400);
            }

            // Check if transfer already exists
            if ($hold->transfer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transfer already exists for this hold.',
                ], 400);
            }

            // Create transfer
            $transfer = $this->stripeService->createTransfer($hold, 'manual');

            return response()->json([
                'success' => true,
                'message' => 'Transfer initiated successfully.',
                'data' => [
                    'transfer_id' => $transfer->id,
                    'stripe_transfer_id' => $transfer->stripe_transfer_id,
                    'status' => $transfer->status,
                    'amount' => $transfer->amount,
                    'currency' => $transfer->currency,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Admin Transfer Failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate transfer.',
            ], 500);
        }
    }
}
