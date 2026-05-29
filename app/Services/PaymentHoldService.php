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
     *   - hold_start_at: full datetime "2026-03-06 14:30:00" or date-only "2026-03-06" (uses current time)
     *   - hold_end_at:   full datetime or date-only (uses current time)
     *   - If end not passed: calculated from hold_days + hold_hours + hold_minutes starting from start
     */
    public function createFromPayment(Payment $payment, array $holdPeriodData = []): PaymentHold
    {
        // Use user's timezone for interpreting input dates, store in UTC
        $userTz = $holdPeriodData['user_timezone'] ?? $payment->user?->timezone ?? 'UTC';
        $holdPeriodType = $holdPeriodData['hold_period_type'] ?? '1_month';
        $holdDays = (int) ($holdPeriodData['hold_days'] ?? 0);
        $holdHours = (int) ($holdPeriodData['hold_hours'] ?? 0);
        $holdMinutes = (int) ($holdPeriodData['hold_minutes'] ?? 0);
        $title = $holdPeriodData['title'] ?? null;

        $now = now(); // UTC

        // hold_start_at: parse in user's timezone, then convert to UTC
        $rawStartDate = $holdPeriodData['hold_start_at'] ?? null;
        if ($rawStartDate) {
            $startDate = Carbon::parse($rawStartDate, $userTz);
            // If only date was passed (no time component), apply current time in user's tz
            if (strlen(trim($rawStartDate)) <= 10) {
                $nowInUserTz = now()->setTimezone($userTz);
                $startDate->setTime($nowInUserTz->hour, $nowInUserTz->minute, $nowInUserTz->second);
            }
            $startDate->setTimezone('UTC');
        } else {
            $startDate = $now->copy();
        }

        // hold_end_at: parse in user's timezone, then convert to UTC
        $rawEndDate = $holdPeriodData['hold_end_at'] ?? null;
        if ($rawEndDate) {
            $endDate = Carbon::parse($rawEndDate, $userTz);
            if (strlen(trim($rawEndDate)) <= 10) {
                $nowInUserTz = now()->setTimezone($userTz);
                $endDate->setTime($nowInUserTz->hour, $nowInUserTz->minute, $nowInUserTz->second);
            }
            $endDate->setTimezone('UTC');
        } else {
            // No end date — calculate from days + hours + minutes
            $endDate = $startDate->copy()
                ->addDays($holdDays > 0 ? $holdDays : 30)
                ->addHours($holdHours)
                ->addMinutes($holdMinutes);
        }

        // Calculate actual duration between start and end
        $totalMinutes = (int) $startDate->diffInMinutes($endDate);
        $holdDays = intdiv($totalMinutes, 1440);       // 1440 minutes in a day
        $holdHours = intdiv($totalMinutes % 1440, 60);
        $holdMinutes = $totalMinutes % 60;

        return PaymentHold::create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'title' => $title,
            'amount' => $payment->amount,
            'remaining_amount' => $payment->amount,
            'hold_start_at' => $startDate,
            'hold_end_at' => $endDate,
            'hold_days' => $holdDays,
            'hold_hours' => $holdHours,
            'hold_minutes' => $holdMinutes,
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
