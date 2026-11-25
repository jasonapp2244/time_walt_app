<?php

namespace App\Console\Commands;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\PaymentHold;
use App\Models\Refund;
use App\Models\Transfer;
use App\Services\DisputeService;
use App\Services\RefundService;
use App\Services\TransferReversalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Compares Stripe with the database and records whatever the webhooks missed,
 * so the admin ledger always matches Stripe:
 *  - refunds (made in the admin panel or the Stripe Dashboard), and refund
 *    status changes;
 *  - chargebacks (disputes) and their outcome;
 *  - withdrawals reversed by hand in Stripe;
 *  - deposits paid in Stripe that have no record here (reported only: the
 *    admin checks them, money is never created automatically).
 *
 * Each record goes through the same code as its live webhook, so nothing is
 * counted twice and running it again changes nothing. It reads Stripe
 * directly: no CSV or file is needed.
 *
 * Dry run by default; --apply records. Runs every night with --apply
 * (routes/console.php); also used once to import events from before the
 * webhooks existed: --since=2026-01-01 --apply.
 */
class StripeSyncMissed extends Command
{
    public const CACHE_KEY = 'stripe:sync-missed:last';

    /** Refund emails go out only for refunds this recent (older ones were handled long ago). */
    private const NOTIFY_WITHIN_DAYS = 3;

    protected $signature = 'stripe:sync-missed
                            {--since= : Check Stripe records created on or after this date (default: the last 120 days)}
                            {--apply : Record what is missing (default is a dry run)}
                            {--notify : Send the refund emails for missed refunds made in the last 3 days}';

    protected $description = 'Compare Stripe with the database and record missed refunds, chargebacks and reversals (dry run unless --apply)';

    private bool $apply = false;

    /** @var array<string, int> */
    private array $counts = ['checked' => 0, 'missing' => 0, 'fixed' => 0, 'errors' => 0];

    /** @var array<int, string> */
    private array $missingDeposits = [];

    public function handle(RefundService $refunds, DisputeService $disputes, TransferReversalService $reversals): int
    {
        try {
            $since = $this->option('since')
                ? Carbon::parse((string) $this->option('since'))->startOfDay()
                : now()->subDays(120)->startOfDay();
        } catch (\Throwable) {
            $this->error('--since must be a date, for example 2026-11-01.');

            return self::FAILURE;
        }

        // Fresh state per run (the command object is reused inside one process).
        $this->apply = (bool) $this->option('apply');
        $this->counts = ['checked' => 0, 'missing' => 0, 'fixed' => 0, 'errors' => 0];
        $this->missingDeposits = [];
        $this->info(($this->apply ? 'Recording what Stripe has and the app missed' : 'DRY RUN: nothing is changed. Add --apply to record').", since {$since->toDateString()}…");

        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
        $filter = ['created' => ['gte' => $since->timestamp], 'limit' => 100];

        $ok = $this->section('Refunds', fn () => \Stripe\Refund::all($filter), fn (array $r) => $this->refund($refunds, $r))
            && $this->section('Chargebacks', fn () => \Stripe\Dispute::all($filter), fn (array $d) => $this->dispute($disputes, $d))
            && $this->section('Withdrawal reversals', fn () => \Stripe\Transfer::all($filter), fn (array $t) => $this->transfer($reversals, $t))
            && $this->section('Deposits', fn () => \Stripe\PaymentIntent::all($filter), fn (array $p) => $this->deposit($p));

        $this->newLine();
        $this->table(
            ['Stripe records checked', $this->apply ? 'Missing → recorded' : 'Missing (would record)', 'Errors'],
            [[$this->counts['checked'], $this->apply ? "{$this->counts['missing']} → {$this->counts['fixed']}" : $this->counts['missing'], $this->counts['errors']]],
        );

        if ($this->missingDeposits !== []) {
            $this->warn(count($this->missingDeposits).' deposit(s) are paid in Stripe but not in the app. Check them; they are not created automatically.');
        }

        if ($this->apply) {
            Cache::forever(self::CACHE_KEY, [
                'at' => now()->toIso8601String(),
                'since' => $since->toDateString(),
                'ok' => $ok && $this->counts['errors'] === 0,
                'missing' => $this->counts['missing'],
                'fixed' => $this->counts['fixed'],
                'errors' => $this->counts['errors'],
                'missing_deposits' => array_slice($this->missingDeposits, 0, 20),
            ]);

            if ($this->counts['missing'] > 0 || $this->missingDeposits !== []) {
                Log::warning('stripe:sync-missed found records the webhooks missed', $this->counts + ['missing_deposits' => count($this->missingDeposits)]);
            }
        }

        return $ok && $this->counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Walk one Stripe list (all pages). False when Stripe could not be read. */
    private function section(string $title, callable $list, callable $check): bool
    {
        try {
            foreach ($list()->autoPagingIterator() as $object) {
                $this->counts['checked']++;

                try {
                    $check($object->toArray());
                } catch (\Throwable $e) {
                    $this->counts['errors']++;
                    $this->error("{$title} …".substr((string) ($object->id ?? ''), -8).": {$e->getMessage()}");
                    Log::error('stripe:sync-missed could not record a Stripe record', ['section' => $title, 'id' => substr((string) ($object->id ?? ''), -8), 'error' => $e->getMessage()]);
                }
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->counts['errors']++;
            $this->error("Could not read {$title} from Stripe: {$e->getMessage()}");

            return false;
        }

        return true;
    }

    private function refund(RefundService $refunds, array $stripeRefund): void
    {
        $paymentIntent = $stripeRefund['payment_intent'] ?? null;

        if (! is_string($paymentIntent) || $paymentIntent === '') {
            return;
        }

        $payment = Payment::where('payment_intent_id_index', Payment::blindIndex($paymentIntent))->first();
        $row = Refund::findByStripeId((string) $stripeRefund['id']) ?? $this->ownRefund($stripeRefund);
        $stripeStatus = $this->refundStatus((string) ($stripeRefund['status'] ?? ''));

        if ($payment === null) {
            // A subscription payment refund: its total is synced idempotently.
            if ($this->apply && $stripeStatus === 'succeeded') {
                $refunds->syncFromStripe($stripeRefund, false);
            }

            return;
        }

        // Already recorded and up to date (a recorded success is never downgraded to pending).
        if ($row !== null && ($row->status === $stripeStatus || ($row->status === 'succeeded' && $stripeStatus === 'pending'))) {
            return;
        }

        if ($row === null && in_array($stripeStatus, ['failed', 'canceled'], true)) {
            return; // never moved money
        }

        $hold = PaymentHold::where('payment_id', $payment->id)->first();
        $this->report(sprintf(
            '%s: refund …%s $%s (%s) on deposit #%s, user #%s%s',
            $row === null ? 'MISSING' : 'OUT OF DATE',
            substr((string) $stripeRefund['id'], -8),
            number_format(((int) ($stripeRefund['amount'] ?? 0)) / 100, 2),
            $this->date($stripeRefund['created'] ?? null),
            $hold ? str_pad((string) $hold->id, 5, '0', STR_PAD_LEFT) : '—',
            $payment->user_id ?? '—',
            $row === null ? '' : " (app says {$row->status}, Stripe says {$stripeStatus})",
        ));

        if ($this->apply) {
            $recent = isset($stripeRefund['created']) && (int) $stripeRefund['created'] >= now()->subDays(self::NOTIFY_WITHIN_DAYS)->timestamp;
            $refunds->syncFromStripe($stripeRefund, $this->option('notify') && $recent);
            $this->counts['fixed']++;
        }
    }

    private function dispute(DisputeService $disputes, array $stripeDispute): void
    {
        $row = Dispute::where('stripe_dispute_id', (string) $stripeDispute['id'])->first();
        $outcome = $disputes->outcome((string) ($stripeDispute['status'] ?? ''));

        if ($row !== null && $row->outcome === $outcome) {
            return;
        }

        $this->report(sprintf(
            '%s: chargeback …%s $%s (%s), Stripe status %s%s',
            $row === null ? 'MISSING' : 'OUT OF DATE',
            substr((string) $stripeDispute['id'], -8),
            number_format(((int) ($stripeDispute['amount'] ?? 0)) / 100, 2),
            $this->date($stripeDispute['created'] ?? null),
            $stripeDispute['status'] ?? '?',
            $row === null ? '' : " (app says {$row->outcome})",
        ));

        if ($this->apply) {
            $disputes->sync($stripeDispute);

            // Chargebacks on payments that are not ours are ignored by the service.
            if (Dispute::where('stripe_dispute_id', (string) $stripeDispute['id'])->exists()) {
                $this->counts['fixed']++;
            } else {
                $this->counts['missing']--;
                $this->line('  (not a Time Vault payment; ignored)');
            }
        }
    }

    private function transfer(TransferReversalService $reversals, array $stripeTransfer): void
    {
        if ((int) ($stripeTransfer['amount_reversed'] ?? 0) <= 0) {
            return;
        }

        $row = Transfer::where('stripe_transfer_id_index', Transfer::blindIndex((string) $stripeTransfer['id']))->first();

        if ($row === null) {
            return; // not a withdrawal this app made
        }

        $manualCents = $reversals->manualReversedCents($stripeTransfer);

        if ($manualCents === null || $manualCents <= (int) round((float) $row->manual_reversed_amount * 100)) {
            return;
        }

        $this->report(sprintf(
            'MISSING: withdrawal #%05d reversed by hand in Stripe: $%s (app has $%s)',
            $row->id,
            number_format($manualCents / 100, 2),
            number_format((float) $row->manual_reversed_amount, 2),
        ));

        if ($this->apply) {
            $reversals->sync($stripeTransfer);
            $this->counts['fixed']++;
        }
    }

    /** A paid deposit Stripe has and the app does not: reported, never created here. */
    private function deposit(array $paymentIntent): void
    {
        if (($paymentIntent['status'] ?? null) !== 'succeeded' || empty($paymentIntent['metadata']['user_id'])) {
            return; // not paid, or not a deposit (subscriptions carry no user_id)
        }

        if (Payment::where('payment_intent_id_index', Payment::blindIndex((string) $paymentIntent['id']))->exists()) {
            return;
        }

        $line = sprintf(
            'pi_…%s $%s (%s), user #%s',
            substr((string) $paymentIntent['id'], -8),
            number_format(((int) ($paymentIntent['amount_received'] ?? $paymentIntent['amount'] ?? 0)) / 100, 2),
            $this->date($paymentIntent['created'] ?? null),
            $paymentIntent['metadata']['user_id'],
        );
        $this->missingDeposits[] = $line;
        $this->warn('NOT IN APP (check by hand): deposit '.$line);
    }

    private function report(string $line): void
    {
        $this->counts['missing']++;
        $this->warn($line);
    }

    /** Our own admin-panel refund, found by metadata when its Stripe ID was never saved. */
    private function ownRefund(array $stripeRefund): ?Refund
    {
        $refundId = $stripeRefund['metadata']['refund_id'] ?? null;

        return is_string($refundId) && ctype_digit($refundId) ? Refund::find((int) $refundId) : null;
    }

    private function refundStatus(string $stripeStatus): string
    {
        return in_array($stripeStatus, ['succeeded', 'failed', 'canceled'], true) ? $stripeStatus : 'pending';
    }

    private function date(mixed $timestamp): string
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp)->format('Y-m-d H:i').' UTC' : '?';
    }
}
