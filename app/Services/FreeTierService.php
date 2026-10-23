<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Free-tier allowance: how many locks and withdrawals a user without a
 * subscription may make. Counters live on the user row and never reset.
 *
 * Deliberately free of other service dependencies, so PaymentHoldService can
 * use it without a constructor cycle.
 */
class FreeTierService
{
    public const LOCK = 'lock';

    public const WITHDRAW = 'withdraw';

    private const COLUMNS = [
        self::LOCK => 'free_locks_used',
        self::WITHDRAW => 'free_withdrawals_used',
    ];

    /**
     * The limit for an action, or null when the action is not limited.
     */
    public function limit(string $action): ?int
    {
        if ($action === self::WITHDRAW && ! $this->withdrawLimitEnabled()) {
            return null;
        }

        $value = $action === self::LOCK
            ? AppSetting::read('free_lock_limit', config('app.free_lock_limit'))
            : AppSetting::read('free_withdraw_limit', config('app.free_withdraw_limit'));

        return max(0, (int) $value);
    }

    public function withdrawLimitEnabled(): bool
    {
        return (bool) (int) AppSetting::read('withdraw_limit_enabled', (int) config('app.withdraw_limit_enabled'));
    }

    /**
     * @return array{action: string, used: int, limit: int|null, left: int|null, subscribed: bool, blocked: bool}
     */
    public function quota(User $user, string $action): array
    {
        $used = (int) $user->{self::COLUMNS[$action]};
        $limit = $this->limit($action);
        $subscribed = $user->isSubscribed();

        return [
            'action' => $action,
            'used' => $used,
            'limit' => $limit,
            'left' => ($subscribed || $limit === null) ? null : max(0, $limit - $used),
            'subscribed' => $subscribed,
            'blocked' => ! $subscribed && $limit !== null && $used >= $limit,
        ];
    }

    /**
     * Count a lock once its hold exists. Subscribers are not counted, so a
     * lapsed subscription does not suddenly find the free allowance spent.
     */
    public function recordLock(User $user): void
    {
        if ($user->isSubscribed()) {
            return;
        }

        User::whereKey($user->id)->increment('free_locks_used');
    }

    /**
     * Claim one free withdrawal before any money moves.
     *
     * The user row is locked so two simultaneous requests cannot both take the
     * last free withdrawal. Returns null when the request may proceed without
     * consuming anything (subscribed, or withdrawals not limited), true when a
     * free withdrawal was claimed, and false when the allowance is spent.
     */
    public function claimWithdrawal(User $user): ?bool
    {
        if ($this->limit(self::WITHDRAW) === null || $user->isSubscribed()) {
            return null;
        }

        return DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first(['id', 'free_withdrawals_used']);

            if ((int) $locked->free_withdrawals_used >= $this->limit(self::WITHDRAW)) {
                return false;
            }

            User::whereKey($user->id)->increment('free_withdrawals_used');

            return true;
        });
    }

    /**
     * Give back a claimed withdrawal when no money was sent.
     */
    public function releaseWithdrawal(User $user): void
    {
        User::whereKey($user->id)
            ->where('free_withdrawals_used', '>', 0)
            ->decrement('free_withdrawals_used');
    }

    /**
     * The 402 body the app reacts to by opening the subscription plans screen.
     */
    public function blockedPayload(array $quota): array
    {
        $noun = $quota['action'] === self::LOCK ? 'locks' : 'withdrawals';

        return [
            'success' => false,
            'code' => 'subscription_required',
            'action' => $quota['action'],
            'used' => $quota['used'],
            'limit' => $quota['limit'],
            'message' => "You have used your {$quota['limit']} free {$noun}. Subscribe to continue.",
        ];
    }
}
