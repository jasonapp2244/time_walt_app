<?php

namespace App\Console\Commands;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\StripeCustomer;
use App\Models\StripeFee;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Copies the fees Stripe kept into stripe_fees, for the admin Ledger.
 *
 * It reads Stripe's balance transactions (the same data as Stripe → Reports →
 * Balance → Fees), so the total always matches Stripe. Each fee is linked to
 * its deposit, subscription payment or chargeback when the app has it; fees
 * that belong to no user (Connect, Radar, account fees) are kept as platform
 * fees. A fee is never dropped.
 *
 * Read-only towards Stripe and the app's money tables: it writes only to
 * stripe_fees. Running it again changes nothing. Dry run unless --apply.
 * Runs every hour (routes/console.php); first run on a server:
 * --since=2026-01-01 --apply.
 */
class StripeSyncFees extends Command
{
    public const CACHE_KEY = 'stripe:sync-fees:last';

    /** Balance transaction types whose whole amount is a Stripe fee (they carry fee = 0). */
    private const FEE_TYPES = ['stripe_fee', 'stripe_fx_fee'];

    protected $signature = 'stripe:sync-fees
                            {--since= : Read Stripe balance transactions created on or after this date (default: the last 3 days)}
                            {--apply : Save the fees (default is a dry run)}';

    protected $description = 'Copy the fees Stripe kept (card, chargeback, Connect) into the admin ledger (dry run unless --apply)';

    /** @var array<string, int> */
    private array $counts = ['checked' => 0, 'fees' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0];

    /** @var array<string, int> cents per category */
    private array $byCategory = [];

    public function handle(): int
    {
        try {
            $since = $this->option('since')
                ? Carbon::parse((string) $this->option('since'))->startOfDay()
                : now()->subDays(3)->startOfDay();
        } catch (\Throwable) {
            $this->error('--since must be a date, for example 2026-11-01.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->counts = ['checked' => 0, 'fees' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0];
        $this->byCategory = [];
        $this->info(($apply ? 'Saving the fees Stripe kept' : 'DRY RUN: nothing is saved. Add --apply to save').", since {$since->toDateString()}…");

        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
        $ok = true;

        try {
            $list = \Stripe\BalanceTransaction::all([
                'created' => ['gte' => $since->timestamp],
                'limit' => 100,
                'expand' => ['data.source'],
            ]);

            foreach ($list->autoPagingIterator() as $transaction) {
                $this->counts['checked']++;

                try {
                    $this->record($transaction->toArray(), $apply);
                } catch (\Throwable $e) {
                    $this->counts['errors']++;
                    $ref = substr((string) ($transaction->id ?? ''), -8);
                    $this->error("Fee …{$ref}: {$e->getMessage()}");
                    Log::error('stripe:sync-fees could not save a fee', ['id' => $ref, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $ok = false;
            $this->counts['errors']++;
            $this->error("Could not read balance transactions from Stripe: {$e->getMessage()}");
            Log::error('stripe:sync-fees could not read Stripe', ['error' => $e->getMessage()]);
        }

        $this->newLine();
        $this->table(
            ['Stripe lines checked', 'With a fee', $apply ? 'New' : 'New (would save)', $apply ? 'Updated' : 'Changed (would update)', 'Errors'],
            [[$this->counts['checked'], $this->counts['fees'], $this->counts['new'], $this->counts['updated'], $this->counts['errors']]],
        );

        if ($this->byCategory !== []) {
            $this->table(['Category', 'Fees'], collect($this->byCategory)
                ->map(fn (int $cents, string $category) => [StripeFee::CATEGORIES[$category] ?? $category, '$'.number_format($cents / 100, 2)])
                ->values()->all());
        }

        $success = $ok && $this->counts['errors'] === 0;

        if ($apply) {
            Cache::forever(self::CACHE_KEY, [
                'at' => now()->toIso8601String(),
                'since' => $since->toDateString(),
                'ok' => $success,
                'new' => $this->counts['new'],
                'errors' => $this->counts['errors'],
            ]);
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /** Save one balance transaction if Stripe kept a fee on it. */
    private function record(array $txn, bool $apply): void
    {
        $type = (string) ($txn['type'] ?? '');
        $feeCents = (int) ($txn['fee'] ?? 0);

        if ($feeCents === 0 && in_array($type, self::FEE_TYPES, true)) {
            $feeCents = -(int) ($txn['amount'] ?? 0); // the line itself is the fee (a negative amount)
        }

        if ($feeCents === 0) {
            return;
        }

        $this->counts['fees']++;
        $link = $this->link($type, is_array($txn['source'] ?? null) ? $txn['source'] : []);

        $values = [
            'type' => $type,
            'category' => $link['category'],
            'description' => isset($txn['description']) ? mb_substr((string) $txn['description'], 0, 255) : null,
            'user_id' => $link['user_id'],
            'payment_id' => $link['payment_id'],
            'subscription_invoice_id' => $link['subscription_invoice_id'],
            'dispute_id' => $link['dispute_id'],
            'gross' => ((int) ($txn['amount'] ?? 0)) / 100,
            'fee' => $feeCents / 100,
            'net' => ((int) ($txn['net'] ?? 0)) / 100,
            'fee_details' => array_map(fn ($d) => [
                'type' => $d['type'] ?? null,
                'amount' => isset($d['amount']) ? ((int) $d['amount']) / 100 : null,
                'description' => $d['description'] ?? null,
            ], $txn['fee_details'] ?? []),
            'currency' => strtolower((string) ($txn['currency'] ?? 'usd')),
            'occurred_at' => Carbon::createFromTimestamp((int) ($txn['created'] ?? time()), 'UTC'),
        ];

        $this->byCategory[$link['category']] = ($this->byCategory[$link['category']] ?? 0) + $feeCents;

        $row = StripeFee::where('balance_transaction_id_index', StripeFee::blindIndex((string) $txn['id']))->first();

        if ($row === null) {
            $this->counts['new']++;

            if ($apply) {
                StripeFee::create(['balance_transaction_id' => (string) $txn['id']] + $values);
            }

            return;
        }

        $row->fill($values);

        if ($row->isDirty(['category', 'user_id', 'payment_id', 'subscription_invoice_id', 'dispute_id', 'fee', 'gross', 'net'])) {
            $this->counts['updated']++;

            if ($apply) {
                $row->save();
            }
        }
    }

    /**
     * Which user and record a fee belongs to.
     *
     * @return array{category: string, user_id: int|null, payment_id: int|null, subscription_invoice_id: int|null, dispute_id: int|null}
     */
    private function link(string $type, array $source): array
    {
        $link = ['category' => 'platform', 'user_id' => null, 'payment_id' => null, 'subscription_invoice_id' => null, 'dispute_id' => null];
        $object = (string) ($source['object'] ?? '');

        if ($object === 'dispute' || str_starts_with((string) ($source['id'] ?? ''), 'dp_')) {
            $link['category'] = 'chargeback';
            $dispute = Dispute::where('stripe_dispute_id', (string) ($source['id'] ?? ''))->first();

            if ($dispute !== null) {
                return ['dispute_id' => $dispute->id, 'payment_id' => $dispute->payment_id, 'subscription_invoice_id' => $dispute->subscription_invoice_id, 'user_id' => $dispute->user_id] + $link;
            }

            return $this->byPaymentIntent($source['payment_intent'] ?? null, $link, keepCategory: true);
        }

        if ($object === 'refund' || in_array($type, ['refund', 'payment_refund'], true)) {
            $link['category'] = 'refund';

            return $this->byPaymentIntent($source['payment_intent'] ?? null, $link, keepCategory: true);
        }

        if ($object === 'charge' || in_array($type, ['charge', 'payment'], true)) {
            $link['category'] = 'other';
            $link = $this->byPaymentIntent($source['payment_intent'] ?? null, $link);

            if ($link['payment_id'] !== null || $link['subscription_invoice_id'] !== null) {
                return $link;
            }

            // A charge the app has no record of: keep its fee, and its user when Stripe says who.
            $userId = $source['metadata']['user_id'] ?? null;

            if (is_scalar($userId) && ctype_digit((string) $userId) && User::whereKey((int) $userId)->exists()) {
                return ['category' => 'deposit', 'user_id' => (int) $userId] + $link;
            }

            if (is_string($source['customer'] ?? null)) {
                $link['user_id'] = StripeCustomer::where('stripe_customer_id_index', StripeCustomer::blindIndex($source['customer']))->value('user_id');
            }

            // Stripe Billing names its charges "Subscription creation", "Subscription update", "Payment for Invoice".
            if (preg_match('/^(subscription |payment for invoice)/i', (string) ($source['description'] ?? ''))) {
                $link['category'] = 'subscription';
            }

            return $link;
        }

        return $link; // stripe_fee, Connect, payout and other account-level fees
    }

    /** Find the deposit, or the subscription payment, behind a payment intent. */
    private function byPaymentIntent(mixed $paymentIntent, array $link, bool $keepCategory = false): array
    {
        $paymentIntent = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;

        if (! is_string($paymentIntent) || $paymentIntent === '') {
            return $link;
        }

        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntent))->first();

        if ($payment !== null) {
            return ['category' => $keepCategory ? $link['category'] : 'deposit', 'payment_id' => $payment->id, 'user_id' => $payment->user_id] + $link;
        }

        $invoice = $this->invoiceFor($paymentIntent);

        if ($invoice !== null) {
            return ['category' => $keepCategory ? $link['category'] : 'subscription', 'subscription_invoice_id' => $invoice->id, 'user_id' => $invoice->user_id] + $link;
        }

        return $link;
    }

    /** The subscription invoice paid by a payment intent (Stripe links them through invoice payments). */
    private function invoiceFor(string $paymentIntent): ?SubscriptionInvoice
    {
        try {
            $payments = \Stripe\InvoicePayment::all([
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntent],
                'limit' => 1,
            ]);
        } catch (\Stripe\Exception\ApiErrorException) {
            return null; // not an invoice payment, or not readable: the fee is still kept
        }

        $invoiceId = $payments->data[0]->invoice ?? null;
        $invoiceId = is_object($invoiceId) ? ($invoiceId->id ?? null) : $invoiceId;

        return is_string($invoiceId)
            ? SubscriptionInvoice::where('stripe_invoice_id_index', SubscriptionInvoice::blindIndex($invoiceId))->first()
            : null;
    }
}
