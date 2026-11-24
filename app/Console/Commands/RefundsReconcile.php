<?php

namespace App\Console\Commands;

use App\Exceptions\RefundException;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Console\Command;

/**
 * Settle a refund stuck in "pending" from Stripe's own records. Use it when a
 * refund is flagged "needs attention" because Stripe gave no clear answer and
 * no webhook has settled it. Safe to run more than once.
 */
class RefundsReconcile extends Command
{
    protected $signature = 'refunds:reconcile {refund : The refund ID shown on the admin Refunds page}';

    protected $description = 'Settle a stuck pending refund from Stripe: follow the card refund if it exists, otherwise put the money back in the vault';

    public function handle(RefundService $refunds): int
    {
        $refund = Refund::find((int) $this->argument('refund'));

        if ($refund === null) {
            $this->error('No refund with that ID.');

            return self::FAILURE;
        }

        try {
            $this->info($refunds->reconcile($refund));
        } catch (RefundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
