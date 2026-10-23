<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for renewals. Access ends at current_period_end unless a webhook
 * moves it forward, so a lost or late webhook would lock out a user who has
 * paid. This re-reads from Stripe only the subscriptions at that point: about
 * to renew or just past it, and first payments Stripe should have expired.
 */
class SyncSubscriptions extends Command
{
    protected $signature = 'subscriptions:sync';

    protected $description = 'Re-read subscriptions due for renewal from Stripe, in case a webhook was missed';

    /** Per run; the hourly schedule picks up any rest next time. */
    private const BATCH = 200;

    /** Older than this is no longer a missed webhook, so stop asking Stripe every hour. */
    private const LOOKBACK_DAYS = 30;

    public function handle(SubscriptionService $subscriptions): int
    {
        $due = Subscription::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q
                    ->whereIn('status', Subscription::ENTITLED_STATUSES)
                    ->whereBetween('current_period_end', [now()->subDays(self::LOOKBACK_DAYS), now()->addHour()]))
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', 'incomplete')
                    ->whereBetween('created_at', [now()->subDays(self::LOOKBACK_DAYS), now()->subHours(23)])))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $failed = 0;

        foreach ($due as $subscription) {
            try {
                $subscriptions->syncFromStripe($subscription->stripe_subscription_id);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $failed++;
                Log::warning('Subscription sync from Stripe failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Checked {$due->count()} subscription(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
