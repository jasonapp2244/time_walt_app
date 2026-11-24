<?php

namespace App\Services;

use App\Jobs\SendManualReversalNotification;
use App\Models\PaymentHold;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A withdrawal (transfer to the user's Connect account) reversed by hand in
 * the Stripe Dashboard (transfer.reversed webhook).
 *
 * The reversed money is back in the platform balance, so it is the user's
 * money in the vault again: it goes back into that deposit's remaining
 * amount, and the admin is told. Reversals made by our own refund code carry
 * metadata.refund_id and are recorded by RefundService; they are skipped here,
 * so nothing is counted twice.
 *
 * Idempotent: the manual total is recomputed from Stripe's full reversal list
 * each time and only the difference since the last event is applied.
 */
class TransferReversalService
{
    public function __construct(
        protected RefundService $refunds
    ) {}

    public function sync(array $stripeTransfer): void
    {
        $transferId = $stripeTransfer['id'] ?? null;

        if (! is_string($transferId) || $transferId === '') {
            return;
        }

        $transfer = Transfer::where('stripe_transfer_id_index', Transfer::blindIndex($transferId))->first();

        if ($transfer === null) {
            Log::info('transfer.reversed for a transfer this app did not make; ignored', ['transfer' => substr($transferId, -8)]);

            return;
        }

        $manualCents = $this->manualReversedCents($stripeTransfer);

        if ($manualCents === null) {
            return; // Stripe could not be read; the next delivery retries.
        }

        $applied = DB::transaction(function () use ($transfer, $manualCents) {
            // Lock order: hold, then transfer (as in StripeService::createTransfer).
            $hold = $transfer->hold_id ? PaymentHold::whereKey($transfer->hold_id)->lockForUpdate()->first() : null;
            $locked = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            $alreadyCents = (int) round((float) $locked->manual_reversed_amount * 100);
            $newCents = $manualCents - $alreadyCents;

            if ($newCents <= 0) {
                return 0;
            }

            $locked->update([
                'reversed_amount' => (round((float) $locked->reversed_amount * 100) + $newCents) / 100,
                'manual_reversed_amount' => $manualCents / 100,
            ]);

            if ($hold) {
                $remaining = (int) round((float) ($hold->remaining_amount ?? 0) * 100) + $newCents;

                $hold->update([
                    'remaining_amount' => $remaining / 100,
                    'status' => $this->refunds->statusFor($hold, $remaining),
                ]);
            }

            return $newCents;
        });

        if ($applied > 0) {
            Log::warning('Withdrawal reversed by hand in Stripe; money returned to the vault', ['transfer_id' => $transfer->id, 'amount' => $applied / 100]);
            SendManualReversalNotification::dispatch($transfer->fresh(), $applied / 100);
        }
    }

    /**
     * Total of the transfer's reversals that our refund code did not make, in
     * cents. Reads the full list from Stripe when the event shows only part.
     */
    private function manualReversedCents(array $stripeTransfer): ?int
    {
        $reversals = $stripeTransfer['reversals']['data'] ?? [];

        if (($stripeTransfer['reversals']['has_more'] ?? false) === true) {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            try {
                $reversals = \Stripe\Transfer::allReversals($stripeTransfer['id'], ['limit' => 100])->toArray()['data'] ?? [];
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::warning('Could not list the transfer reversals from Stripe', ['error' => $e->getMessage()]);

                return null;
            }
        }

        $cents = 0;

        foreach ($reversals as $reversal) {
            if (empty($reversal['metadata']['refund_id'])) {
                $cents += (int) ($reversal['amount'] ?? 0);
            }
        }

        return $cents;
    }
}
