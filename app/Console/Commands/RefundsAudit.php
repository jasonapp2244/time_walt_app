<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\RefundController;
use App\Models\PaymentHold;
use App\Models\Refund;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read-only check that every refunded deposit still adds up:
 *
 *   hold amount = remaining_amount + (transfers − reversed) + active refunds
 *
 * A mismatch is printed, logged, and shown on the admin Refunds page.
 * Nothing is corrected automatically.
 */
class RefundsAudit extends Command
{
    protected $signature = 'refunds:audit';

    protected $description = 'Check that refunded deposits add up (read-only); mismatches are shown on the admin Refunds page';

    public function handle(): int
    {
        $holds = PaymentHold::query()
            ->where(function ($query) {
                $query->whereHas('refunds')
                    ->orWhereHas('disputes', fn ($d) => $d->where('outcome', 'lost'))
                    ->orWhereHas('transfers', fn ($t) => $t->where('reversed_amount', '>', 0));
            })
            ->with(['transfers', 'refunds', 'disputes'])
            ->get();

        $mismatches = [];

        foreach ($holds as $hold) {
            $transfers = $hold->transfers
                ->whereIn('status', ['completed', 'pending', 'processing'])
                ->sum(fn ($t) => $this->cents($t->amount) - $this->cents($t->reversed_amount));

            $refunds = $hold->refunds
                ->whereIn('status', Refund::ACTIVE_STATUSES)
                ->sum(fn ($r) => $this->cents($r->amount));

            // A lost chargeback took its vault part out (the rest was already withdrawn).
            $disputes = $hold->disputes
                ->where('outcome', 'lost')
                ->sum(fn ($d) => $this->cents($d->vault_amount));

            $accounted = $this->cents($hold->remaining_amount ?? 0) + $transfers + $refunds + $disputes;
            $difference = $this->cents($hold->amount) - $accounted;

            if ($difference !== 0) {
                $mismatches[] = [
                    'hold_id' => $hold->id,
                    'amount' => (float) $hold->amount,
                    'remaining' => (float) $hold->remaining_amount,
                    'transferred' => (float) ($transfers / 100),
                    'refunded' => (float) ($refunds / 100),
                    'difference' => (float) ($difference / 100),
                ];
            }
        }

        Cache::forever(RefundController::AUDIT_CACHE_KEY, $mismatches);

        if ($mismatches === []) {
            $this->info("All {$holds->count()} refunded deposit(s) add up.");

            return self::SUCCESS;
        }

        $this->table(
            ['Hold', 'Amount', 'Remaining', 'Transferred (net)', 'Refunded', 'Difference'],
            array_map(fn ($m) => [$m['hold_id'], $m['amount'], $m['remaining'], $m['transferred'], $m['refunded'], $m['difference']], $mismatches),
        );

        Log::error('Refund audit found mismatches', ['count' => count($mismatches), 'holds' => array_column($mismatches, 'hold_id')]);

        $this->error(count($mismatches).' deposit(s) do not add up. Nothing was changed.');

        return self::FAILURE;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
