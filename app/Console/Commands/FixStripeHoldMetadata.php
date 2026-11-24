<?php

namespace App\Console\Commands;

use App\Models\PaymentHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * One-off repair of the hold-length labels on deposits already in Stripe.
 *
 * Deposits made before 2026-11-25 carry hold_days = 30 in their PaymentIntent
 * metadata whatever the real lock was, because the app sends only dates. The
 * hold rows in the database were always right; this copies their real length
 * (and the unlock time as hold_until) onto each PaymentIntent.
 *
 * Only metadata keys are written: no money moves and nothing else on the
 * payment changes. Dry run by default; --apply writes. Safe to run again:
 * PaymentIntents that already match are skipped.
 */
class FixStripeHoldMetadata extends Command
{
    protected $signature = 'stripe:fix-hold-metadata
                            {--apply : Write the corrected labels to Stripe (default is a dry run)}
                            {--limit=0 : Stop after this many deposits (0 = all)}';

    protected $description = 'Correct the hold_days label on old deposits in Stripe from the real hold in the database (dry run unless --apply)';

    public function handle(): int
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $counts = ['checked' => 0, 'fixed' => 0, 'already_ok' => 0, 'skipped' => 0, 'errors' => 0];

        $this->info($apply ? 'Writing corrected hold labels to Stripe…' : 'DRY RUN: nothing is written. Add --apply to fix.');

        $query = PaymentHold::query()->whereNotNull('payment_id')->whereNotNull('hold_end_at')->with('payment')->orderBy('id');

        foreach ($query->lazyById(100) as $hold) {
            if ($limit > 0 && $counts['checked'] >= $limit) {
                break;
            }

            $counts['checked']++;
            $paymentIntentId = $hold->payment ? rescue(fn () => $hold->payment->payment_intent_id, null, false) : null;

            if (! is_string($paymentIntentId) || ! str_starts_with($paymentIntentId, 'pi_')) {
                $counts['skipped']++;

                continue;
            }

            $wanted = [
                'hold_days' => (string) (int) $hold->hold_days,
                'hold_hours' => (string) (int) $hold->hold_hours,
                'hold_minutes' => (string) (int) $hold->hold_minutes,
                'hold_until' => $hold->hold_end_at->copy()->utc()->toIso8601String(),
            ];

            try {
                $intent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
                $current = $intent->metadata ? $intent->metadata->toArray() : [];

                // Never relabel a PaymentIntent that is not this user's deposit.
                if ((string) ($current['user_id'] ?? '') !== (string) $hold->user_id) {
                    $counts['skipped']++;
                    $this->warn("Hold #{$hold->id}: the Stripe payment does not belong to this user; skipped.");

                    continue;
                }

                $changes = array_diff_assoc($wanted, array_intersect_key($current, $wanted));

                if ($changes === []) {
                    $counts['already_ok']++;

                    continue;
                }

                $this->line(sprintf(
                    'Hold #%05d …%s: hold_days %s → %s',
                    $hold->id,
                    substr($paymentIntentId, -8),
                    $current['hold_days'] ?? '(none)',
                    $wanted['hold_days'],
                ));

                if ($apply) {
                    \Stripe\PaymentIntent::update($paymentIntentId, ['metadata' => $wanted], [
                        'idempotency_key' => 'tv-fix-hold-meta-'.$hold->id.'-'.md5(json_encode($wanted)),
                    ]);
                }

                $counts['fixed']++;
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $counts['errors']++;
                $this->error("Hold #{$hold->id}: Stripe error: {$e->getMessage()}");
                Log::warning('stripe:fix-hold-metadata failed for a hold', ['hold_id' => $hold->id, 'error' => $e->getMessage()]);
            }
        }

        $this->newLine();
        $this->table(['Checked', $apply ? 'Fixed' : 'Would fix', 'Already correct', 'Skipped', 'Errors'], [array_values($counts)]);

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
