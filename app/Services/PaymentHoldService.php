<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentHold;
use Carbon\Carbon;

class PaymentHoldService
{
    /**
     * Create payment hold from payment with hold period data.
     */
    public function createFromPayment(Payment $payment, array $holdPeriodData = []): PaymentHold
    {
        $startDate = $holdPeriodData['hold_start_at'] ?? now();
        $endDate = $holdPeriodData['hold_end_at'] ?? null;
        $holdDays = $holdPeriodData['hold_days'] ?? 30;
        $holdPeriodType = $holdPeriodData['hold_period_type'] ?? '1_month';
        $title = $holdPeriodData['title'] ?? null;

        // If dates provided as strings, parse them
        if (is_string($startDate)) {
            $startDate = Carbon::parse($startDate);
        }

        // Calculate end date if not provided
        if (! $endDate) {
            $endDate = $startDate->copy()->addDays($holdDays);
        } else {
            if (is_string($endDate)) {
                $endDate = Carbon::parse($endDate);
            }
            // Recalculate days from actual dates
            $holdDays = $startDate->diffInDays($endDate);
        }

        return PaymentHold::create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'title' => $title,
            'amount' => $payment->amount,
            'remaining_amount' => $payment->amount, // Initialize remaining_amount = amount
            'hold_start_at' => $startDate,
            'hold_end_at' => $endDate,
            'hold_days' => $holdDays,
            'hold_period_type' => $holdPeriodType,
            'status' => 'holding',
        ]);
    }

    /**
     * Check and mark holds as ready for transfer (for cron job).
     */
    public function checkAndMarkReady(): int
    {
        $holds = PaymentHold::where('status', 'holding')
            ->where('hold_end_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($holds as $hold) {
            $hold->update([
                'status' => 'ready_for_transfer',
                'ready_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }
}
