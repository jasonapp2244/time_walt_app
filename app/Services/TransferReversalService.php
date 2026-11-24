<?php

namespace App\Services;

use App\Jobs\SendManualReversalNotification;
use App\Models\Dispute;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A withdrawal (transfer to the user's Connect account) reversed by hand in
 * the Stripe Dashboard (transfer.reversed webhook).
 *
 * The reversed money is back in the platform balance. It first settles what
 * the user owes on that deposit (a lost chargeback or a Dashboard refund the
 * vault could not cover); only the rest is the user's money again and goes
 * back into the deposit's remaining amount. The admin is told either way. Reversals made by our own refund code carry
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
                return 0; // nothing new since the last event
            }

            $locked->update([
                'reversed_amount' => (round((float) $locked->reversed_amount * 100) + $newCents) / 100,
                'manual_reversed_amount' => $manualCents / 100,
            ]);

            // Money the user withdrew but owes back (a lost chargeback or a
            // Dashboard refund larger than the vault) is covered first, so the
            // recovered money can never be withdrawn a second time.
            $covered = $hold ? $this->coverShortfalls($hold, $newCents) : 0;
            $toVault = $newCents - $covered;

            if ($hold) {
                $remaining = (int) round((float) ($hold->remaining_amount ?? 0) * 100) + $toVault;

                $hold->update([
                    'remaining_amount' => $remaining / 100,
                    'status' => $this->refunds->statusFor($hold, $remaining),
                ]);
            }

            return ['vault' => $toVault, 'covered' => $covered];
        });

        if ($applied !== 0) {
            Log::warning('Withdrawal reversed by hand in Stripe', ['transfer_id' => $transfer->id, 'to_vault' => $applied['vault'] / 100, 'covered_owed' => $applied['covered'] / 100]);
            SendManualReversalNotification::dispatch($transfer->fresh(), $applied['vault'] / 100, $applied['covered'] / 100);
        }
    }

    /**
     * Use reversed cents to settle what the user owes on this deposit: first
     * lost chargebacks, then Dashboard refunds, both flagged because the vault
     * could not cover them. Returns the cents used.
     */
    private function coverShortfalls(PaymentHold $hold, int $cents): int
    {
        $used = 0;

        $disputes = Dispute::where('payment_hold_id', $hold->id)
            ->where('outcome', 'lost')->where('needs_attention', true)
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($disputes as $dispute) {
            $owed = (int) round((float) $dispute->amount * 100) - (int) round((float) $dispute->vault_amount * 100);
            $take = min(max(0, $owed), $cents - $used);

            if ($take <= 0) {
                continue;
            }

            $settled = $take === $owed;
            $dispute->update([
                // The ledger counts this column as money that left with the chargeback.
                'vault_amount' => ((int) round((float) $dispute->vault_amount * 100) + $take) / 100,
                'needs_attention' => ! $settled,
                'note' => $settled
                    ? 'Recovered by a transfer reversal in Stripe.'
                    : '$'.number_format(($owed - $take) / 100, 2).' of this chargeback is still owed by the user (part was recovered by a transfer reversal).',
            ]);
            $used += $take;
        }

        $refunds = Refund::where('payment_hold_id', $hold->id)
            ->where('source', 'stripe_dashboard')->where('needs_attention', true)
            ->whereIn('status', Refund::ACTIVE_STATUSES)
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($refunds as $refund) {
            $owed = (int) round((float) $refund->amount * 100)
                - (int) round((float) $refund->vault_amount * 100)
                - (int) round((float) $refund->reversed_amount * 100);
            $take = min(max(0, $owed), $cents - $used);

            if ($take <= 0) {
                continue;
            }

            $settled = $take === $owed;
            $refund->update([
                'reversed_amount' => ((int) round((float) $refund->reversed_amount * 100) + $take) / 100,
                'needs_attention' => ! $settled,
                'failure_reason' => $settled
                    ? null
                    : '$'.number_format(($owed - $take) / 100, 2).' of this refund is still not covered (part was recovered by a transfer reversal).',
            ]);
            $used += $take;
        }

        return $used;
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
