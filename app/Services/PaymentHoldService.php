<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentHold;
use Carbon\Carbon;

class PaymentHoldService
{
    /**
     * Create payment hold from payment with hold period data.
     *
     * Date handling:
     *   - hold_start_at: passed date + current time  e.g. "2026-03-06" → 2026-03-06 15:32:04
     *   - hold_end_at:   passed date + current time  e.g. "2026-03-07" → 2026-03-07 15:32:04
     *   - If not passed:  calculated from hold_days starting now
     */
    public function createFromPayment(Payment $payment, array $holdPeriodData = []): PaymentHold
    {
        $appTz = config('app.timezone');
        $holdPeriodType = $holdPeriodData['hold_period_type'] ?? '1_month';
        $holdDays = (int) ($holdPeriodData['hold_days'] ?? 30);
        $title = $holdPeriodData['title'] ?? null;

        // Current time components — applied to both dates
        $now = now();
        $h = $now->hour;
        $m = $now->minute;
        $s = $now->second;

        // hold_start_at: passed date + current time
        $rawStartDate = $holdPeriodData['hold_start_at'] ?? null;
        if ($rawStartDate) {
            $startDate = Carbon::parse($rawStartDate, $appTz)->setTime($h, $m, $s);
        } else {
            $startDate = $now->copy();
        }

        // hold_end_at: passed date + current time
        $rawEndDate = $holdPeriodData['hold_end_at'] ?? null;
        if ($rawEndDate) {
            $endDate = Carbon::parse($rawEndDate, $appTz)->setTime($h, $m, $s);
        } else {
            // No end date — calculate from hold_days
            $endDate = $startDate->copy()->addDays($holdDays);
        }

        // Actual hold days between start and end
        $holdDays = (int) $startDate->diffInDays($endDate);

        return PaymentHold::create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'title' => $title,
            'amount' => $payment->amount,
            'remaining_amount' => $payment->amount,
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
