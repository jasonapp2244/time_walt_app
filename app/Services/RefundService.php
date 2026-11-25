<?php

namespace App\Services;

use App\Exceptions\RefundException;
use App\Exceptions\StripeOutcomeUnknownException;
use App\Jobs\SendRefundFailedNotification;
use App\Jobs\SendRefundIssuedNotification;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin refund of one deposit, in full or in part, back to the card that paid it.
 *
 * The refund is created on the deposit's own PaymentIntent, and Stripe can
 * only return that money to the payment method that paid it, so it cannot
 * reach any other card. The admin's amount is checked against the maximum
 * computed from the database (and the Connect balance) under the hold lock.
 *
 * Money that already left the vault for the user's Connect account is pulled
 * back with a transfer reversal first. Every Stripe call carries an
 * idempotency key, and the hold row is locked while the refund is set up, so
 * a double submit, a parallel withdrawal or a second admin cannot pay twice.
 */
class RefundService
{
    /** Transfer statuses whose money has left the vault but may still come back. */
    private const IN_FLIGHT_TRANSFER = ['pending', 'processing'];

    private const UNKNOWN_OUTCOME_MESSAGE = 'Stripe gave no clear answer. The money stays on hold and the refund is flagged on the Refunds page; do not refund this deposit again. If the Stripe webhook has not settled it within 15 minutes, run: php artisan refunds:reconcile ';

    /** A refund younger than this may still be settled by its own request or webhook. */
    private const RECONCILE_AFTER_MINUTES = 15;

    public function __construct()
    {
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Why this deposit cannot be refunded at all, from the database alone (no
     * Stripe call), or null when some amount may be. Used for the button on
     * every table row.
     */
    public function blockReason(PaymentHold $hold): ?string
    {
        try {
            $this->capacity($hold);
        } catch (RefundException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Everything the refund popup shows, computed fresh on the server. When
     * part of the money sits in the user's Connect account, that account's
     * balance is checked with Stripe, so the maximum is what can really go back.
     *
     * @return array<string, mixed>
     */
    public function preview(PaymentHold $hold): array
    {
        $hold->loadMissing(['user', 'payment']);
        $payment = $hold->payment;
        $user = $hold->user;

        $reason = null;
        $note = null;
        $cap = null;
        $maxCents = 0;
        $connectCents = 0;

        try {
            $cap = $this->capacity($hold);

            try {
                $available = $this->connectAvailable($cap);
            } catch (RefundException $e) {
                // Without the balance only the vault part is safe to offer.
                $available = array_fill_keys(array_keys($this->reversibleByAccount($cap)), 0);
                $note = $e->getMessage().' Only the money still in the vault can be refunded right now.';
            }

            $maxCents = $this->maxRefundable($cap, $available);
            $connectCents = max(0, $maxCents - min($cap['vault_cents'], $maxCents));

            // Only the Connect money the account no longer holds counts as "moved to the bank".
            $paidOut = 0;

            foreach ($this->reversibleByAccount($cap) as $account => $cents) {
                $paidOut += $cents - min($cents, max(0, $available[$account] ?? 0));
            }

            if ($paidOut > 0 && $note === null) {
                $note = 'The user already moved $'.number_format($paidOut / 100, 2).' of this deposit to their bank. That part cannot be refunded here.';
            }

            if ($maxCents <= 0) {
                throw new RefundException($note ?? 'Nothing is left to refund on this deposit.');
            }
        } catch (RefundException $e) {
            $reason = $e->getMessage();
        }

        $currency = strtolower($payment?->currency ?? 'usd');
        $card = Refund::formatCard($payment?->card_brand, $payment?->card_last4);
        $paymentIntent = $payment ? rescue(fn () => $payment->payment_intent_id, null, false) : null;

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'note' => $reason === null ? $note : null,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->displayName('Unknown') ?? 'Deleted user',
                'email' => $user?->displayEmail() ?? '—',
                'url' => $user ? route('admin.users.show', $user->id) : null,
            ],
            'card' => [
                'brand' => $payment?->card_brand,
                'last4' => $payment?->card_last4,
                'funding' => $payment?->card_funding,
                'label' => $card,
            ],
            'deposit' => [
                'hold_id' => $hold->id,
                'hold_ref' => str_pad((string) $hold->id, 5, '0', STR_PAD_LEFT),
                'title' => $hold->title,
                'status' => $hold->status,
                'deposited_at' => $hold->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A'),
                'unlock_at' => $hold->hold_end_at?->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A'),
                'payment_ref' => $paymentIntent ? '…'.substr($paymentIntent, -8) : '—',
            ],
            'money' => [
                'deposit' => (float) $hold->amount,
                'already_refunded' => (float) ($payment?->refunded_amount ?? 0),
                'in_vault' => $cap ? $cap['vault_cents'] / 100 : 0.0,
                'from_connect_max' => $connectCents / 100,
                'max_refundable' => $maxCents / 100,
                'currency' => strtoupper($currency),
            ],
            'card_label' => $card,
            'refund_url' => route('admin.payment-holds.refund', $hold->id),
        ];
    }

    /**
     * Refund $amountCents of the deposit to its card: the whole deposit or a
     * part of it. Returns the refund row (succeeded, or pending at Stripe);
     * throws RefundException with an admin-readable message when it is refused
     * or Stripe rejects it.
     *
     * The amount is checked against the maximum again under the hold lock, and
     * $requestToken (one per opened popup) makes a resubmitted form a no-op.
     */
    public function refundHold(PaymentHold $hold, User $admin, int $amountCents, string $reasonCode, string $reasonNote, string $requestToken): Refund
    {
        if (Refund::where('request_token', $requestToken)->exists()) {
            throw new RefundException('This refund was already submitted. Reload the page to see its result.');
        }

        // The Connect balance check is a Stripe call, so it runs before the lock.
        $cap = $this->capacity($hold);
        $available = $amountCents > $cap['vault_cents'] ? $this->connectAvailable($cap) : null;
        $checked = $this->plan($cap, $amountCents, $available);

        try {
            [$refund, $plan] = DB::transaction(function () use ($hold, $admin, $amountCents, $reasonCode, $reasonNote, $requestToken, $available, $checked) {
                $locked = PaymentHold::whereKey($hold->id)->lockForUpdate()->firstOrFail();
                $plan = $this->plan($this->capacity($locked), $amountCents, $available);

                if ($plan['needed_cents'] !== $checked['needed_cents'] || $plan['vault_cents'] !== $checked['vault_cents']) {
                    throw new RefundException('This deposit changed while the refund was being prepared. Reload the page and try again.');
                }

                $payment = $plan['payment'];

                $refund = Refund::create([
                    'payment_id' => $payment->id,
                    'payment_hold_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'admin_id' => $admin->id,
                    'request_token' => $requestToken,
                    'amount' => $plan['amount_cents'] / 100,
                    'vault_amount' => $plan['vault_cents'] / 100,
                    'reversed_amount' => 0,
                    'currency' => $plan['currency'],
                    'card_brand' => $payment->card_brand,
                    'card_last4' => $payment->card_last4,
                    'reason_code' => $reasonCode,
                    'reason_note' => $reasonNote,
                    'source' => 'admin',
                    'status' => 'pending',
                    'hold_status_before' => $locked->status,
                    'reversals' => [],
                ]);

                // The vault part leaves the hold now, so a withdrawal cannot spend it meanwhile.
                $locked->update(['remaining_amount' => ($plan['vault_total_cents'] - $plan['vault_cents']) / 100]);

                return [$refund, $plan];
            });
        } catch (UniqueConstraintViolationException) {
            throw new RefundException('This refund was already submitted. Reload the page to see its result.');
        }

        Log::info('Admin refund started', [
            'refund_id' => $refund->id,
            'hold_id' => $hold->id,
            'admin_id' => $admin->id,
            'amount' => $refund->amount,
            'from_connect' => $plan['needed_cents'] / 100,
        ]);

        $this->reverseTransfers($refund, $plan);

        $stripeRefund = $this->createStripeRefund($refund, $plan);

        $this->accept($refund, $stripeRefund);

        $refund->refresh();

        if ($refund->status === 'failed' || $refund->status === 'canceled') {
            throw new RefundException('Stripe could not refund this card: '.($refund->failure_reason ?: 'unknown reason').'. The money is back in the vault.');
        }

        return $refund;
    }

    /**
     * Keep a refund row in step with Stripe (refund.created / refund.updated /
     * refund.failed). A refund that has no row was made in the Stripe
     * Dashboard; it is recorded and taken out of the hold. $notify = false
     * records it without emails (used when importing old refunds).
     */
    public function syncFromStripe(array $stripeRefund, bool $notify = true): void
    {
        $refund = $this->findRow($stripeRefund);

        if ($refund === null) {
            $this->recordDashboardRefund($stripeRefund, $notify);

            return;
        }

        $this->applyStripeState($refund, $stripeRefund);
    }

    /**
     * charge.refunded carries the refunds only when they are expanded on the
     * charge; refund.created covers the rest.
     */
    public function syncFromCharge(array $charge): void
    {
        // Not a deposit: it may be a subscription payment refunded in Stripe.
        $paymentIntent = $charge['payment_intent'] ?? null;

        if (is_string($paymentIntent) && $paymentIntent !== ''
            && ! Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntent))->exists()) {
            app(SubscriptionChargeService::class)->recordFromCharge($charge);

            return;
        }

        foreach ($charge['refunds']['data'] ?? [] as $stripeRefund) {
            if (is_array($stripeRefund) && isset($stripeRefund['id'])) {
                $this->syncFromStripe($stripeRefund);
            }
        }
    }

    /**
     * Settle a refund that is stuck in "pending" (Stripe gave no clear answer,
     * or its webhook never came) from Stripe's own records:
     *  - the card refund exists → the row follows it;
     *  - it does not → any reversal Stripe made for it is recorded, and all of
     *    the money goes back to the vault (the refund failed).
     * Returns a line for the admin.
     */
    public function reconcile(Refund $refund): string
    {
        if ($refund->status !== 'pending') {
            throw new RefundException("Refund #{$refund->id} is {$refund->status}; only a pending refund can be reconciled.");
        }

        if ($refund->created_at?->gt(now()->subMinutes(self::RECONCILE_AFTER_MINUTES))) {
            throw new RefundException('This refund is less than '.self::RECONCILE_AFTER_MINUTES.' minutes old. Give the Stripe webhook time to settle it first.');
        }

        try {
            if ($refund->stripe_refund_id !== null) {
                $this->applyStripeState($refund, \Stripe\Refund::retrieve($refund->stripe_refund_id)->toArray());

                return "Refund #{$refund->id} now matches Stripe: ".$refund->fresh()->status.'.';
            }

            if ($refund->source !== 'admin') {
                throw new RefundException('A Stripe Dashboard refund without a Stripe ID cannot be reconciled.');
            }

            $paymentIntent = $refund->payment ? rescue(fn () => $refund->payment->payment_intent_id, null, false) : null;

            if (! $paymentIntent) {
                throw new RefundException('The Stripe payment of this refund cannot be read.');
            }

            foreach (\Stripe\Refund::all(['payment_intent' => $paymentIntent, 'limit' => 100])->data as $stripeRefund) {
                $data = $stripeRefund->toArray();

                if ((string) ($data['metadata']['refund_id'] ?? '') === (string) $refund->id) {
                    $this->applyStripeState($refund, $data);

                    return "Stripe made this card refund. Refund #{$refund->id} is now ".$refund->fresh()->status.'.';
                }
            }

            // No card refund at Stripe. Record any reversal it did make for
            // this refund, so the money is counted where it really is.
            $recorded = array_column($refund->reversals ?? [], 'reversal_id');
            $transfers = $refund->hold ? $refund->hold->transfers()->where('status', 'completed')->get() : collect();
            $found = 0;

            foreach ($transfers as $transfer) {
                $stripeTransfer = rescue(fn () => $transfer->stripe_transfer_id, null, false);

                if (! $stripeTransfer) {
                    continue;
                }

                foreach (\Stripe\Transfer::allReversals($stripeTransfer, ['limit' => 100])->data as $reversal) {
                    $data = $reversal->toArray();

                    if ((string) ($data['metadata']['refund_id'] ?? '') === (string) $refund->id && ! in_array($data['id'], $recorded, true)) {
                        $this->recordReversal($refund, $transfer, $data['id'], (int) $data['amount']);
                        $found++;
                    }
                }
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RefundException('Could not read Stripe: '.$e->getMessage().'. Nothing was changed; try again later.');
        }

        $this->fail($refund->fresh(), 'No card refund was made at Stripe (checked by refunds:reconcile). All of the money is back in the vault.');

        return "No card refund exists at Stripe for refund #{$refund->id}. Marked failed; the money is back in the vault"
            .($found > 0 ? " (including {$found} reversal(s) Stripe made from the user's Connect account)." : '.');
    }

    // ─── Planning ────────────────────────────────────────────────────────────

    /**
     * Where the deposit's money is and how much of it may go back, from the
     * database alone. Throws when nothing at all can be refunded.
     *
     * @return array{payment: Payment, payment_intent: string, currency: string, vault_cents: int, charge_left_cents: int, reversible_cents: int, reversible: array<int, array{transfer: Transfer, net_cents: int, account: string}>}
     */
    private function capacity(PaymentHold $hold): array
    {
        $payment = $hold->payment()->first();

        if ($hold->abandoned_at !== null) {
            throw new RefundException('This deposit was abandoned and cannot be refunded.');
        }

        if ($payment === null || $payment->status !== 'succeeded') {
            throw new RefundException('The payment for this deposit did not succeed, so there is nothing to refund.');
        }

        $paymentIntent = rescue(fn () => $payment->payment_intent_id, null, false);

        if (! $paymentIntent) {
            throw new RefundException('The Stripe payment for this deposit cannot be read, so it cannot be refunded here.');
        }

        if ($hold->status === 'refunded') {
            throw new RefundException('This deposit has already been fully refunded.');
        }

        if ($hold->status === 'canceled') {
            throw new RefundException('This deposit was canceled, so there is nothing to refund.');
        }

        if ($hold->disputed_at !== null) {
            throw new RefundException('This deposit has an open chargeback (dispute). It is frozen until the bank decides.');
        }

        if ($hold->refunds()->where('status', 'pending')->exists()) {
            throw new RefundException('A refund for this deposit is already in progress. Wait for it to finish.');
        }

        $transfers = $hold->transfers()->orderByDesc('id')->get();

        if ($transfers->whereIn('status', self::IN_FLIGHT_TRANSFER)->isNotEmpty()) {
            throw new RefundException('A withdrawal is in progress for this deposit. Try again after it completes.');
        }

        if ($transfers->where('transfer_type', 'account_deletion_forfeited')->isNotEmpty()) {
            throw new RefundException('The funds of this deposit were forfeited when the account was deleted.');
        }

        if ($this->cents($hold->amount) <= 0 || $this->cents($hold->amount) > $this->cents($payment->amount)) {
            throw new RefundException('The deposit amount does not match its payment. Check this deposit before refunding.');
        }

        $reversible = [];

        foreach ($transfers->where('status', 'completed') as $transfer) {
            $net = $this->cents($transfer->amount) - $this->cents($transfer->reversed_amount);
            $stripeId = rescue(fn () => $transfer->stripe_transfer_id, null, false);
            $account = rescue(fn () => $transfer->stripe_connect_account_id, null, false);

            if ($net > 0 && $stripeId && is_string($account) && str_starts_with($account, 'acct_')) {
                $reversible[] = ['transfer' => $transfer, 'net_cents' => $net, 'account' => $account];
            }
        }

        $cap = [
            'payment' => $payment,
            'payment_intent' => $paymentIntent,
            'currency' => strtolower($payment->currency ?: 'usd'),
            'vault_cents' => $this->vaultCents($hold, $transfers),
            // Money lost to a chargeback has already gone back to the customer.
            'charge_left_cents' => max(0, $this->cents($payment->amount) - $this->cents($payment->refunded_amount) - $this->cents($payment->disputed_amount)),
            'reversible_cents' => array_sum(array_column($reversible, 'net_cents')),
            'reversible' => $reversible,
        ];

        if ($this->maxRefundable($cap, null) <= 0) {
            throw new RefundException($cap['charge_left_cents'] <= 0
                ? 'This deposit has already been fully refunded.'
                : 'Nothing is left to refund: this deposit\'s money has left Stripe.');
        }

        return $cap;
    }

    /**
     * The most that can go back to the card: the vault plus what can be pulled
     * back from Connect (limited by each account's balance when it is known),
     * never more than is left on the charge.
     *
     * @param  array<string, int>|null  $available  cents per Connect account; null = not checked
     */
    private function maxRefundable(array $cap, ?array $available): int
    {
        $fromConnect = 0;

        foreach ($this->reversibleByAccount($cap) as $account => $cents) {
            $fromConnect += $available === null ? $cents : min($cents, max(0, $available[$account] ?? 0));
        }

        return max(0, min($cap['vault_cents'] + $fromConnect, $cap['charge_left_cents']));
    }

    /**
     * Split a refund of $amountCents: vault first, then reversals, newest
     * withdrawal first. Throws when it is more than may be refunded.
     *
     * @param  array<string, int>|null  $available
     * @return array{payment: Payment, payment_intent: string, currency: string, amount_cents: int, vault_total_cents: int, vault_cents: int, needed_cents: int, transfers: array<int, array{transfer: Transfer, cents: int}>}
     */
    private function plan(array $cap, int $amountCents, ?array $available): array
    {
        if ($amountCents <= 0) {
            throw new RefundException('Enter an amount greater than $0.00.');
        }

        $max = $this->maxRefundable($cap, $available);

        if ($amountCents > $max) {
            throw new RefundException('You can refund at most $'.number_format($max / 100, 2).' of this deposit right now.');
        }

        $vaultCents = min($cap['vault_cents'], $amountCents);
        $neededCents = $amountCents - $vaultCents;
        $left = $neededCents;
        $spent = [];
        $transfers = [];

        foreach ($cap['reversible'] as $item) {
            if ($left <= 0) {
                break;
            }

            $room = $available === null ? $item['net_cents'] : max(0, ($available[$item['account']] ?? 0) - ($spent[$item['account']] ?? 0));
            $take = min($item['net_cents'], $room, $left);

            if ($take <= 0) {
                continue;
            }

            $transfers[] = ['transfer' => $item['transfer'], 'cents' => $take];
            $spent[$item['account']] = ($spent[$item['account']] ?? 0) + $take;
            $left -= $take;
        }

        if ($left > 0) {
            throw new RefundException('$'.number_format($left / 100, 2).' of this amount cannot be pulled back from the user\'s Connect account.');
        }

        return [
            'payment' => $cap['payment'],
            'payment_intent' => $cap['payment_intent'],
            'currency' => $cap['currency'],
            'amount_cents' => $amountCents,
            'vault_total_cents' => $cap['vault_cents'],
            'vault_cents' => $vaultCents,
            'needed_cents' => $neededCents,
            'transfers' => $transfers,
        ];
    }

    /**
     * Money still in the vault for this hold. A null remaining_amount means
     * "never touched": the amount minus whatever transfers and refunds took.
     */
    private function vaultCents(PaymentHold $hold, Collection $transfers): int
    {
        if ($hold->remaining_amount !== null) {
            return max(0, $this->cents($hold->remaining_amount));
        }

        $out = $transfers->whereIn('status', ['completed', 'pending', 'processing'])
            ->sum(fn (Transfer $t) => $this->cents($t->amount) - $this->cents($t->reversed_amount));

        // amount = remaining + (transfers − reversed) + refunds, so the full
        // refund amount (vault part + reversed part) comes off here.
        $refunded = $hold->refunds()->whereIn('status', Refund::ACTIVE_STATUSES)->sum('amount');
        $disputed = \App\Models\Dispute::lostVaultTotal($hold->id);

        return max(0, $this->cents($hold->amount) - $out - $this->cents($refunded) - $this->cents($disputed));
    }

    /** Money still in the vault for this hold, in cents (shared with DisputeService). */
    public function vaultCentsOf(PaymentHold $hold): int
    {
        return $this->vaultCents($hold, $hold->transfers()->get());
    }

    /** The hold status that matches where its money is (shared with DisputeService). */
    public function statusFor(PaymentHold $hold, int $remainingCents): string
    {
        return $this->settledStatus($hold, $remainingCents);
    }

    /** @return array<string, int> reversible cents per Connect account */
    private function reversibleByAccount(array $cap): array
    {
        $byAccount = [];

        foreach ($cap['reversible'] as $item) {
            $byAccount[$item['account']] = ($byAccount[$item['account']] ?? 0) + $item['net_cents'];
        }

        return $byAccount;
    }

    /**
     * What each of the deposit's Connect accounts still holds, available now,
     * in the deposit's currency. Money the user paid out to their bank is gone.
     *
     * @return array<string, int>
     */
    private function connectAvailable(array $cap): array
    {
        $available = [];

        foreach (array_keys($this->reversibleByAccount($cap)) as $account) {
            try {
                $balance = \Stripe\Balance::retrieve(['stripe_account' => $account]);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                throw new RefundException('Could not check the user\'s Stripe balance: '.$e->getMessage().'.');
            }

            $available[$account] = 0;

            foreach ($balance->available ?? [] as $funds) {
                if (strtolower($funds->currency) === $cap['currency']) {
                    $available[$account] += (int) $funds->amount;
                }
            }
        }

        return $available;
    }

    // ─── Stripe calls ────────────────────────────────────────────────────────

    /**
     * Pull the Connect part back, newest withdrawal first. On a refusal the
     * refund stops before the card is touched and the money is restored.
     */
    private function reverseTransfers(Refund $refund, array $plan): void
    {
        foreach ($plan['transfers'] as $item) {
            /** @var Transfer $transfer */
            $transfer = $item['transfer'];
            $params = [
                'amount' => $item['cents'],
                'description' => "Refund #{$refund->id} of hold #{$refund->payment_hold_id}",
                'metadata' => ['refund_id' => $refund->id, 'hold_id' => $refund->payment_hold_id, 'user_id' => $refund->user_id],
            ];
            $options = ['idempotency_key' => "tv-refund-{$refund->id}-rev-{$transfer->id}"];

            try {
                $reversal = $this->withOneRetry(fn () => \Stripe\Transfer::createReversal($transfer->stripe_transfer_id, $params, $options));
            } catch (StripeOutcomeUnknownException $e) {
                $this->leaveForReview($refund, 'No clear answer from Stripe while pulling money back from the user\'s Connect account.', $e);

                throw new RefundException(self::UNKNOWN_OUTCOME_MESSAGE.$refund->id.'.');
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $this->fail($refund, 'Pulling the money back from the user\'s Connect account failed: '.$e->getMessage());

                throw new RefundException('Stripe refused to pull the money back from the user\'s Connect account: '.$e->getMessage().' Nothing was refunded to the card and the money is back in the vault.');
            }

            $this->recordReversal($refund, $transfer, $reversal->id, $item['cents']);
        }

        $refund->refresh();
    }

    /** A reversal Stripe made for this refund: the transfer and the refund row both carry it. */
    private function recordReversal(Refund $refund, Transfer $transfer, string $reversalId, int $cents): void
    {
        DB::transaction(function () use ($refund, $transfer, $reversalId, $cents) {
            $lockedRefund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $lockedTransfer = Transfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            $lockedTransfer->update(['reversed_amount' => ($this->cents($lockedTransfer->reversed_amount) + $cents) / 100]);

            $reversals = $lockedRefund->reversals ?? [];
            $reversals[] = ['transfer_id' => $transfer->id, 'reversal_id' => $reversalId, 'amount' => $cents / 100];

            $lockedRefund->update([
                'reversed_amount' => ($this->cents($lockedRefund->reversed_amount) + $cents) / 100,
                'reversals' => $reversals,
            ]);
        });
    }

    private function createStripeRefund(Refund $refund, array $plan): \Stripe\Refund
    {
        $params = [
            'payment_intent' => $plan['payment_intent'],
            'amount' => $plan['amount_cents'],
            'metadata' => [
                'refund_id' => $refund->id,
                'hold_id' => $refund->payment_hold_id,
                'user_id' => $refund->user_id,
                'admin_id' => $refund->admin_id,
            ],
        ];

        if (in_array($refund->reason_code, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
            $params['reason'] = $refund->reason_code;
        }

        try {
            return $this->withOneRetry(fn () => \Stripe\Refund::create($params, ['idempotency_key' => "tv-refund-{$refund->id}"]));
        } catch (StripeOutcomeUnknownException $e) {
            $this->leaveForReview($refund, 'No clear answer from Stripe while creating the card refund. The Stripe webhook usually settles it.', $e);

            throw new RefundException(self::UNKNOWN_OUTCOME_MESSAGE.$refund->id.'.');
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->fail($refund, $e->getMessage());

            throw new RefundException('Stripe could not refund this card: '.$e->getMessage().' The money is back in the vault.');
        }
    }

    /**
     * A network error leaves the outcome unknown, so the same request (same
     * idempotency key) is sent once more; Stripe answers it without acting twice.
     */
    private function withOneRetry(callable $call): mixed
    {
        try {
            return $call();
        } catch (\Stripe\Exception\ApiConnectionException|\Stripe\Exception\UnknownApiErrorException $first) {
            try {
                return $call();
            } catch (\Stripe\Exception\ApiErrorException $retry) {
                // The first attempt may still have gone through, so even a
                // clear refusal on the retry does not prove nothing happened.
                throw new StripeOutcomeUnknownException($retry->getMessage(), 0, $first);
            }
        }
    }

    // ─── State changes ───────────────────────────────────────────────────────

    /**
     * Stripe accepted the refund. The first time (no Stripe ID stored yet) the
     * deposit is marked refunded; later calls only follow the status.
     */
    private function accept(Refund $refund, \Stripe\Refund|array $stripeRefund): void
    {
        $data = $stripeRefund instanceof \Stripe\Refund ? $stripeRefund->toArray() : $stripeRefund;
        $status = $this->mapStatus($data['status'] ?? 'pending');

        if ($status === 'failed' || $status === 'canceled') {
            $this->fail($refund, $data['failure_reason'] ?? 'Refund '.$status.' at Stripe', $data);

            return;
        }

        $issued = DB::transaction(function () use ($refund, $data, $status) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['failed', 'canceled'], true)) {
                // A failed row with no Stripe ID failed on our side before any
                // card refund existed; Stripe now reporting one means the money
                // went out twice (card + vault). Never silently ignore that.
                if ($locked->stripe_refund_id === null && ! empty($data['id'])) {
                    $locked->fill([
                        'stripe_refund_id' => $data['id'],
                        'needs_attention' => true,
                        'failure_reason' => 'Stripe reports a card refund ('.$status.') for a refund marked failed here, and the money was put back in the vault. Check this deposit before any withdrawal.',
                    ])->save();

                    Log::error('Stripe refund exists for a refund marked failed', ['refund_id' => $locked->id]);
                }

                return null;
            }

            $firstAccept = $locked->stripe_refund_id === null;

            // Webhooks can arrive out of order: a late "pending" snapshot must
            // not undo "succeeded", or the row would block the deposit forever.
            if ($locked->status === 'succeeded') {
                $status = 'succeeded';
            }

            $locked->fill([
                'stripe_refund_id' => $data['id'],
                'status' => $status,
                'needs_attention' => $firstAccept ? false : $locked->needs_attention,
                'failure_reason' => $firstAccept ? null : $locked->failure_reason,
                'arn' => $this->arn($data) ?? $locked->arn,
                'refunded_at' => $status === 'succeeded' ? ($locked->refunded_at ?? now()) : $locked->refunded_at,
            ])->save();

            if (! $firstAccept) {
                return null;
            }

            $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->first();
            $payment?->update([
                'refunded_amount' => ($this->cents($payment->refunded_amount) + $this->cents($locked->amount)) / 100,
                'refunded_at' => now(),
            ]);

            // The vault part already left the hold when the refund was set up;
            // only the status follows (refunded once nothing is left anywhere).
            $hold = ($locked->source === 'admin' && $locked->payment_hold_id)
                ? PaymentHold::whereKey($locked->payment_hold_id)->lockForUpdate()->first()
                : null;

            $hold?->update(['status' => $this->settledStatus($hold, $this->cents($hold->remaining_amount))]);

            return $locked;
        });

        Log::info('Refund accepted by Stripe', ['refund_id' => $refund->id, 'status' => $status]);

        if ($issued !== null) {
            SendRefundIssuedNotification::dispatch($issued);
        }
    }

    /**
     * The refund did not reach the card. Whatever left the vault for it (the
     * vault part plus anything pulled back from Connect) is the user's money
     * again and goes back into the hold.
     */
    private function fail(Refund $refund, string $reason, array $stripeData = []): void
    {
        $failed = DB::transaction(function () use ($refund, $reason, $stripeData) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['failed', 'canceled'], true)) {
                return null;
            }

            $wasAccepted = $locked->stripe_refund_id !== null;
            $status = ($stripeData['status'] ?? null) === 'canceled' ? 'canceled' : 'failed';

            if (isset($stripeData['id']) && ! $wasAccepted) {
                $locked->stripe_refund_id = $stripeData['id'];
            }

            $locked->fill([
                'status' => $status,
                'failure_reason' => mb_substr($reason, 0, 1000),
                'needs_attention' => false,
            ])->save();

            if ($wasAccepted) {
                $payment = Payment::whereKey($locked->payment_id)->lockForUpdate()->first();
                $payment?->update([
                    'refunded_amount' => max(0, $this->cents($payment->refunded_amount) - $this->cents($locked->amount)) / 100,
                ]);
            }

            $restore = $this->cents($locked->vault_amount) + $this->cents($locked->reversed_amount);
            $hold = $locked->payment_hold_id ? PaymentHold::whereKey($locked->payment_hold_id)->lockForUpdate()->first() : null;

            if ($hold) {
                $remaining = $this->cents($hold->remaining_amount) + $restore;

                $hold->update([
                    'remaining_amount' => $remaining / 100,
                    'status' => $this->settledStatus($hold, $remaining),
                ]);
            }

            return $locked;
        });

        if ($failed !== null) {
            // The vault is back to a balance it had before, so the next
            // withdrawal would reuse an old transfer idempotency key and Stripe
            // would replay that (possibly reversed) transfer instead of paying.
            if ($failed->payment_hold_id) {
                app(StripeService::class)->transferRejected($failed->payment_hold_id);
            }

            Log::warning('Refund failed; money restored to the vault', ['refund_id' => $refund->id, 'reason' => $reason]);
            SendRefundFailedNotification::dispatch($failed);
        }
    }

    /**
     * Stripe gave no answer even after a retry: the refund may or may not
     * exist. The money stays out of the vault (the safe side) and the row is
     * flagged; the refund webhook settles it.
     */
    private function leaveForReview(Refund $refund, string $reason, \Throwable $e): void
    {
        $refund->update([
            'needs_attention' => true,
            'failure_reason' => $reason.' If it is not settled within '.self::RECONCILE_AFTER_MINUTES." minutes, run: php artisan refunds:reconcile {$refund->id}",
        ]);

        Log::error('Refund outcome unknown; needs review', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);
        SendRefundFailedNotification::dispatch($refund->fresh());
    }

    /**
     * The hold status that matches where its money now is. A deposit becomes
     * "refunded" only when nothing is left in the vault or in Connect; after a
     * partial refund it keeps a normal status, so the rest stays usable.
     */
    private function settledStatus(PaymentHold $hold, int $remainingCents): string
    {
        $netOut = $hold->transfers()->whereIn('status', ['completed', 'pending', 'processing'])->get()
            ->sum(fn (Transfer $t) => $this->cents($t->amount) - $this->cents($t->reversed_amount));

        if ($remainingCents <= 0) {
            return $netOut > 0 ? 'transferred' : 'refunded';
        }

        if ($hold->hold_end_at !== null && $hold->hold_end_at->isFuture()) {
            return 'holding';
        }

        if ($netOut > 0) {
            return 'partial_transferred';
        }

        return $hold->status === 'holding' ? 'holding' : 'ready_for_transfer';
    }

    // ─── Webhook helpers ─────────────────────────────────────────────────────

    private function findRow(array $stripeRefund): ?Refund
    {
        if (! empty($stripeRefund['id']) && ($row = Refund::findByStripeId($stripeRefund['id']))) {
            return $row;
        }

        $refundId = $stripeRefund['metadata']['refund_id'] ?? null;

        if (! $refundId || ! ctype_digit((string) $refundId)) {
            return null;
        }

        $row = Refund::with('payment')->find((int) $refundId);
        $paymentIntent = $row?->payment ? rescue(fn () => $row->payment->payment_intent_id, null, false) : null;

        // Only trust the metadata when it points at the same payment.
        return ($row && $paymentIntent && $paymentIntent === ($stripeRefund['payment_intent'] ?? null)) ? $row : null;
    }

    private function applyStripeState(Refund $refund, array $stripeRefund): void
    {
        $status = $this->mapStatus($stripeRefund['status'] ?? 'pending');

        if ($status === 'failed' || $status === 'canceled') {
            $this->fail($refund, $stripeRefund['failure_reason'] ?? 'Refund '.$status.' at Stripe', $stripeRefund);

            return;
        }

        $this->accept($refund, $stripeRefund);
    }

    /**
     * A refund made directly in the Stripe Dashboard. It is recorded so the
     * vault cannot pay the same money out again. If more was refunded than
     * the vault still held, the row is flagged for the admin.
     */
    private function recordDashboardRefund(array $stripeRefund, bool $notify = true): void
    {
        // When Stripe made the refund (matters when it is imported days later).
        $madeAt = isset($stripeRefund['created']) && is_numeric($stripeRefund['created'])
            ? \Illuminate\Support\Carbon::createFromTimestamp((int) $stripeRefund['created'])
            : now();
        $status = $this->mapStatus($stripeRefund['status'] ?? 'pending');
        $paymentIntent = $stripeRefund['payment_intent'] ?? null;

        if ($status === 'failed' || $status === 'canceled' || ! is_string($paymentIntent) || $paymentIntent === '') {
            return;
        }

        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntent))->first();

        if ($payment === null) {
            // Not a deposit: record it on the subscription payment if it is one.
            if (! app(SubscriptionChargeService::class)->recordFromRefund($stripeRefund)) {
                Log::info('Refund webhook for a payment that is neither a deposit nor a subscription; ignored', ['refund' => substr((string) ($stripeRefund['id'] ?? ''), -8)]);
            }

            return;
        }

        $amountCents = (int) ($stripeRefund['amount'] ?? 0);

        try {
            $refund = DB::transaction(function () use ($payment, $stripeRefund, $status, $amountCents, $madeAt) {
                // Same lock order as accept()/fail(): payment, then hold.
                $lockedPayment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                $hold = PaymentHold::where('payment_id', $payment->id)->lockForUpdate()->first();

                // Same rule as an admin refund: a null remaining_amount means the
                // amount minus what withdrawals and earlier refunds already took.
                $vaultBefore = $hold ? $this->vaultCents($hold, $hold->transfers()->get()) : 0;
                $fromVault = min($vaultBefore, $amountCents);
                $uncovered = $amountCents - $fromVault;

                $refund = Refund::create([
                    'payment_id' => $lockedPayment->id,
                    'payment_hold_id' => $hold?->id,
                    'user_id' => $lockedPayment->user_id,
                    'stripe_refund_id' => $stripeRefund['id'],
                    'amount' => $amountCents / 100,
                    'vault_amount' => $fromVault / 100,
                    'reversed_amount' => 0,
                    'currency' => strtolower($stripeRefund['currency'] ?? $lockedPayment->currency ?? 'usd'),
                    'card_brand' => $lockedPayment->card_brand,
                    'card_last4' => $lockedPayment->card_last4,
                    'reason_code' => in_array($stripeRefund['reason'] ?? null, ['duplicate', 'fraudulent', 'requested_by_customer'], true) ? $stripeRefund['reason'] : 'other',
                    'reason_note' => 'Refunded in the Stripe Dashboard.',
                    'source' => 'stripe_dashboard',
                    'status' => $status,
                    'hold_status_before' => $hold?->status,
                    'arn' => $this->arn($stripeRefund),
                    'needs_attention' => $uncovered > 0,
                    'failure_reason' => $uncovered > 0 ? '$'.number_format($uncovered / 100, 2).' was refunded that the vault no longer held (already withdrawn). Check this deposit.' : null,
                    'refunded_at' => $status === 'succeeded' ? $madeAt : null,
                ]);

                $refundedTotal = $this->cents($lockedPayment->refunded_amount) + $amountCents;
                $lockedPayment->update(['refunded_amount' => $refundedTotal / 100, 'refunded_at' => $madeAt]);

                if ($hold) {
                    $remaining = $vaultBefore - $fromVault;

                    $hold->update([
                        'remaining_amount' => $remaining / 100,
                        'status' => $this->settledStatus($hold, $remaining),
                    ]);
                }

                return $refund;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel event for the same refund recorded it first.
            if ($row = Refund::findByStripeId($stripeRefund['id'])) {
                $this->applyStripeState($row, $stripeRefund);
            }

            return;
        }

        if ($refund->needs_attention) {
            Log::error('Stripe Dashboard refund exceeds the vault balance', ['refund_id' => $refund->id, 'payment_id' => $payment->id]);
        }

        if ($notify) {
            SendRefundIssuedNotification::dispatch($refund);
        }
    }

    private function mapStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'succeeded' => 'succeeded',
            'failed' => 'failed',
            'canceled' => 'canceled',
            default => 'pending', // pending, requires_action
        };
    }

    /** The bank tracking number (ARN) once Stripe has it. */
    private function arn(array $stripeRefund): ?string
    {
        $card = $stripeRefund['destination_details']['card'] ?? null;

        return is_array($card) && ($card['reference_type'] ?? null) === 'acquirer_reference_number' && ! empty($card['reference'])
            ? (string) $card['reference']
            : null;
    }

    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
