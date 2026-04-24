<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\ConfirmPaymentRequest;
use App\Http\Requests\Payment\CreatePaymentIntentRequest;
use App\Services\PaymentSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentSheetController extends Controller
{
    public function __construct(
        protected PaymentSheetService $paymentSheetService
    ) {}

    /**
     * Create PaymentIntent for Payment Sheet.
     * Returns client_secret, customer_id, ephemeral_key, publishable_key.
     */
    public function createPaymentIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $result = $this->paymentSheetService->createPaymentIntent($user, [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'hold_period_type' => $request->hold_period_type,
                'hold_start_at' => $request->hold_start_at,
                'hold_end_at' => $request->hold_end_at,
                'hold_days' => $request->hold_days,
                'title' => $request->title,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment intent created. Use client_secret to present Payment Sheet.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Create Payment Intent (Payment Sheet) Failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent.',
            ], 500);
        }
    }

    /**
     * Confirm payment after Payment Sheet succeeds.
     * Creates Payment + PaymentHold records.
     */
    public function confirmPayment(ConfirmPaymentRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            $result = $this->paymentSheetService->confirmPayment(
                $request->payment_intent_id,
                $user
            );

            $message = ($result['already_recorded'] ?? false)
                ? 'Payment already recorded.'
                : 'Payment confirmed and records created successfully.';

            unset($result['already_recorded']);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Confirm Payment (Payment Sheet) Failed: ' . $e->getMessage());

            $message = 'Failed to confirm payment.';
            if (str_contains($e->getMessage(), 'not completed yet')) {
                $message = $e->getMessage();
            } elseif (str_contains($e->getMessage(), 'does not belong')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 400);
        }
    }
}
