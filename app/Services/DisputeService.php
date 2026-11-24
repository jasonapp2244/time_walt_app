<?php

namespace App\Services;

use App\Jobs\SendDisputeNotification;
use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Chargebacks (Stripe disputes) on deposits, from the charge.dispute.*
 * webhooks.
 *
 *  - open (created / updated): the deposit is frozen (payment_holds.disputed_at),
 *    so no withdrawal and no refund can move its money while the bank decides.
 *  - won: the freeze is lifted; the money stays the user's.
 *  - lost: the customer got the money back through the bank. The disputed
 *    amount comes out of the vault, so it can never be paid out again; any
 *    part the user had already withdrawn is flagged for the admin.
 *
 * Every change runs under row locks and only on a real state change, so
 * repeated or out-of-order webhooks are harmless.
 */
class DisputeService
{
    public function __construct(
        protected RefundService $refunds
    ) {}

    public function sync(array $stripeDispute): void
    {
        $disputeId = $stripeDispute['id'] ?? null;

        if (! is_string($disputeId) || $disputeId === '') {
            return;
        }

        $payment = $this->findPayment($stripeDispute);

        if ($payment === null) {
            Log::info('Dispute webhook for a payment that is not a deposit; ignored', ['dispute' => substr($disputeId, -8)]);

            return;
        }

        $outcome = $this->outcome((string) ($stripeDispute['status'] ?? ''));

        try {
            [$dispute, $event] = DB::transaction(fn () => $this->apply($payment, $stripeDispute, $outcome));
        } catch (UniqueConstraintViolationException) {
            // A parallel delivery of the same dispute created the row first; apply on top of it.
            [$dispute, $event] = DB::transaction(fn () => $this->apply($payment, $stripeDispute, $outcome));
        }

        if ($event !== null) {
            Log::warning('Deposit dispute '.$event, ['dispute_id' => $dispute->id, 'hold_id' => $dispute->payment_hold_id, 'amount' => $dispute->amount]);
            SendDisputeNotification::dispatch($dispute, $event);
        }
    }

    /**
     * @return array{0: Dispute, 1: string|null} the row and what changed (opened / won / lost), if anything
     */
    private function apply(Payment $payment, array $stripeDispute, string $outcome): array
    {
        // Lock order shared with RefundService: payment, then hold.
        $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $hold = PaymentHold::where('payment_id', $payment->id)->lockForUpdate()->first();
        $dispute = Dispute::where('stripe_dispute_id', $stripeDispute['id'])->lockForUpdate()->first();

        $amountCents = (int) ($stripeDispute['amount'] ?? 0);
        $dueBy = $stripeDispute['evidence_details']['due_by'] ?? null;
        $details = [
            'stripe_status' => (string) ($stripeDispute['status'] ?? 'unknown'),
            'reason' => isset($stripeDispute['reason']) ? mb_substr((string) $stripeDispute['reason'], 0, 50) : null,
            'evidence_due_by' => is_numeric($dueBy) ? Carbon::createFromTimestamp((int) $dueBy) : null,
        ];

        if ($dispute === null) {
            $dispute = Dispute::create($details + [
                'payment_id' => $lockedPayment->id,
                'payment_hold_id' => $hold?->id,
                'user_id' => $lockedPayment->user_id,
                'stripe_dispute_id' => $stripeDispute['id'],
                'amount' => $amountCents / 100,
                'currency' => strtolower((string) ($stripeDispute['currency'] ?? $lockedPayment->currency ?? 'usd')),
                'outcome' => 'open',
            ]);
            $isNew = true;
        } else {
            $dispute->fill($details)->save();
            $isNew = false;
        }

        // A decided dispute is final; later events only refresh the details above.
        if ($dispute->outcome !== 'open') {
            return [$dispute, null];
        }

        if ($outcome === 'open') {
            if ($hold && $hold->disputed_at === null) {
                $hold->update(['disputed_at' => now()]);
            }

            return [$dispute, $isNew ? 'opened' : null];
        }

        if ($outcome === 'won') {
            $dispute->update(['outcome' => 'won', 'closed_at' => now()]);
            $this->unfreezeIfNoOtherOpen($hold, $dispute);

            return [$dispute, 'won'];
        }

        // Lost: the bank gave the customer the money back. Take it out of the vault.
        $vaultBefore = $hold ? $this->refunds->vaultCentsOf($hold) : 0;
        $fromVault = min($vaultBefore, $amountCents);
        $uncovered = $amountCents - $fromVault;

        $dispute->update([
            'outcome' => 'lost',
            'closed_at' => now(),
            'vault_amount' => $fromVault / 100,
            'needs_attention' => $uncovered > 0,
            'note' => $uncovered > 0
                ? '$'.number_format($uncovered / 100, 2).' of this chargeback was already withdrawn by the user. Recover it from the user before any new payout.'
                : null,
        ]);

        $lockedPayment->update([
            'disputed_amount' => (round((float) $lockedPayment->disputed_amount * 100) + $amountCents) / 100,
        ]);

        if ($hold) {
            $remaining = $vaultBefore - $fromVault;
            $hold->update([
                'remaining_amount' => $remaining / 100,
                'status' => $this->refunds->statusFor($hold, $remaining),
            ]);
            $this->unfreezeIfNoOtherOpen($hold, $dispute);
        }

        return [$dispute->refresh(), 'lost'];
    }

    private function unfreezeIfNoOtherOpen(?PaymentHold $hold, Dispute $dispute): void
    {
        if ($hold === null) {
            return;
        }

        $otherOpen = Dispute::where('payment_hold_id', $hold->id)
            ->where('outcome', 'open')
            ->whereKeyNot($dispute->id)
            ->exists();

        if (! $otherOpen) {
            $hold->update(['disputed_at' => null]);
        }
    }

    /** won and warning_closed (an inquiry that ended) keep the money with the user. */
    private function outcome(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'won', 'warning_closed' => 'won',
            'lost' => 'lost',
            default => 'open', // warning_needs_response, warning_under_review, needs_response, under_review
        };
    }

    private function findPayment(array $stripeDispute): ?Payment
    {
        $paymentIntent = $stripeDispute['payment_intent'] ?? null;

        // Older API versions only carry the charge; ask Stripe for its PaymentIntent.
        if ((! is_string($paymentIntent) || $paymentIntent === '') && is_string($stripeDispute['charge'] ?? null)) {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $paymentIntent = rescue(
                fn () => \Stripe\Charge::retrieve($stripeDispute['charge'])->payment_intent,
                null,
                fn ($e) => Log::warning('Could not read the disputed charge from Stripe', ['error' => $e->getMessage()]),
            );
        }

        if (! is_string($paymentIntent) || $paymentIntent === '') {
            return null;
        }

        return Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntent))->first();
    }
}
