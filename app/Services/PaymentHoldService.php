<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentHold;
use Carbon\Carbon;

class PaymentHoldService
{
    public function __construct(
        protected FreeTierService $freeTier
    ) {}

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
        $title = $holdPeriodData['title'] ?? null;

        $period = self::resolvePeriod($holdPeriodData, $userTz);

        $hold = PaymentHold::create([
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'title' => $title,
            'amount' => $payment->amount,
            'remaining_amount' => $payment->amount,
            'hold_start_at' => $period['start'],
            'hold_end_at' => $period['end'],
            'hold_days' => $period['days'],
            'hold_hours' => $period['hours'],
            'hold_minutes' => $period['minutes'],
            'hold_period_type' => $holdPeriodType,
            'status' => 'holding',
        ]);

        // Every hold is created here (Payment Sheet confirm and both webhook
        // paths), so this is the one place a lock is counted.
        if ($payment->user) {
            $this->freeTier->recordLock($payment->user);
        }

        return $hold;
    }

    /**
     * The hold's real start, end and length (days / hours / minutes) from the
     * lock request. The end date wins when it is given; otherwise the end is
     * start + days/hours/minutes (30 days when nothing is given). Used both
     * for the hold row and for the PaymentIntent metadata, so the Stripe
     * Dashboard shows the same length as the app.
     *
     * @return array{start: Carbon, end: Carbon, days: int, hours: int, minutes: int}
     */
    public static function resolvePeriod(array $holdPeriodData, string $userTz): array
    {
        $holdDays = (int) ($holdPeriodData['hold_days'] ?? 0);
        $holdHours = (int) ($holdPeriodData['hold_hours'] ?? 0);
        $holdMinutes = (int) ($holdPeriodData['hold_minutes'] ?? 0);

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

        return [
            'start' => $startDate,
            'end' => $endDate,
            'days' => intdiv($totalMinutes, 1440),       // 1440 minutes in a day
            'hours' => intdiv($totalMinutes % 1440, 60),
            'minutes' => $totalMinutes % 60,
        ];
    }
}
