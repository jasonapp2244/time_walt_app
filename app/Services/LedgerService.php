<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SubscriptionInvoice;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The money ledger: every transaction of every user in one list, read
 * straight from the tables the app already keeps (payments, transfers,
 * refunds, disputes, subscription invoices). Nothing is copied or stored
 * twice, so the ledger can never drift from the records it shows.
 *
 * Each line has an "effect" on the user's vault (the money Time Vault still
 * holds for them):
 *   deposit +, withdrawal −, money pulled back from a withdrawal +,
 *   refund −, lost chargeback − (the part taken from the vault).
 * Failed payments, failed withdrawals and failed refunds are listed with no
 * effect (no money moved). Subscription lines are revenue, not vault money,
 * so their effect is empty.
 *
 * The sum of the effects is the same formula refunds:audit checks:
 * amount = remaining + (transfers − reversed) + refunds + lost chargebacks.
 */
class LedgerService
{
    /** Filter groups shown on the page => the line kinds in each. */
    public const GROUPS = [
        'deposits' => ['payment'],
        'withdrawals' => ['withdrawal'],
        'reversals' => ['reversal_refund', 'reversal_manual'],
        'refunds' => ['refund'],
        'chargebacks' => ['chargeback_opened', 'chargeback_won', 'chargeback_lost'],
        'subscriptions' => ['subscription', 'subscription_refund'],
    ];

    public const GROUP_LABELS = [
        'deposits' => 'Deposits',
        'withdrawals' => 'Withdrawals',
        'reversals' => 'Pulled back',
        'refunds' => 'Refunds',
        'chargebacks' => 'Chargebacks',
        'subscriptions' => 'Subscriptions',
    ];

    /** Transfer statuses whose money has left (or is leaving) the vault. */
    private const TRANSFER_OUT = "('completed','pending','processing')";

    /**
     * Every ledger line as one query (a UNION of the source tables).
     */
    public function lines(): Builder
    {
        $payments = DB::table('payments')->selectRaw(
            "'payment' as kind, id as source_id, user_id,
             (select ph.id from payment_holds ph where ph.payment_id = payments.id order by ph.id limit 1) as hold_id,
             coalesce(paid_at, created_at) as occurred_at, amount,
             case when status = 'succeeded' then amount else 0 end as effect,
             status, 10 as seq"
        );

        $withdrawals = DB::table('transfers')->selectRaw(
            "'withdrawal' as kind, id as source_id, user_id, hold_id,
             coalesce(transferred_at, created_at) as occurred_at, amount,
             case when status in ".self::TRANSFER_OUT.' then -amount else 0 end as effect,
             status, 30 as seq'
        );

        // Pulled back by our refund code: dated by the refund that needed it.
        $refundReversals = DB::table('transfers')
            ->whereRaw('reversed_amount - coalesce(manual_reversed_amount, 0) > 0')
            ->selectRaw(
                "'reversal_refund' as kind, id as source_id, user_id, hold_id,
                 coalesce((select min(r.created_at) from refunds r where r.payment_hold_id = transfers.hold_id and r.reversed_amount > 0), updated_at) as occurred_at,
                 reversed_amount - coalesce(manual_reversed_amount, 0) as amount,
                 reversed_amount - coalesce(manual_reversed_amount, 0) as effect,
                 status, 35 as seq"
            );

        $manualReversals = DB::table('transfers')
            ->where('manual_reversed_amount', '>', 0)
            ->selectRaw(
                "'reversal_manual' as kind, id as source_id, user_id, hold_id,
                 updated_at as occurred_at, manual_reversed_amount as amount, manual_reversed_amount as effect,
                 status, 36 as seq"
            );

        $refunds = DB::table('refunds')->selectRaw(
            "'refund' as kind, id as source_id, user_id, payment_hold_id as hold_id,
             coalesce(refunded_at, created_at) as occurred_at, amount,
             case when status in ('pending','succeeded') then -amount else 0 end as effect,
             status, 40 as seq"
        );

        $disputesOpened = DB::table('disputes')->selectRaw(
            "'chargeback_opened' as kind, id as source_id, user_id, payment_hold_id as hold_id,
             created_at as occurred_at, amount,
             case when payment_id is null then null else 0 end as effect,
             outcome as status, 50 as seq"
        );

        $disputesClosed = DB::table('disputes')
            ->whereIn('outcome', ['won', 'lost'])
            ->selectRaw(
                "concat('chargeback_', outcome) as kind, id as source_id, user_id, payment_hold_id as hold_id,
                 coalesce(closed_at, updated_at) as occurred_at, amount,
                 case when payment_id is null then null when outcome = 'lost' then -vault_amount else 0 end as effect,
                 outcome as status, 51 as seq"
            );

        $subscriptions = DB::table('subscription_invoices')->selectRaw(
            "'subscription' as kind, id as source_id, user_id, null as hold_id,
             coalesce(paid_at, created_at) as occurred_at, amount, null as effect,
             'paid' as status, 60 as seq"
        );

        $subscriptionRefunds = DB::table('subscription_invoices')
            ->where('refunded_amount', '>', 0)
            ->selectRaw(
                "'subscription_refund' as kind, id as source_id, user_id, null as hold_id,
                 coalesce(refunded_at, updated_at) as occurred_at, refunded_amount as amount, null as effect,
                 'refunded' as status, 61 as seq"
            );

        $union = $payments
            ->unionAll($withdrawals)
            ->unionAll($refundReversals)
            ->unionAll($manualReversals)
            ->unionAll($refunds)
            ->unionAll($disputesOpened)
            ->unionAll($disputesClosed)
            ->unionAll($subscriptions)
            ->unionAll($subscriptionRefunds);

        return DB::query()->fromSub($union, 'ledger');
    }

    /**
     * The lines matching the filters: user_id, group, from, to (admin dates).
     */
    public function filtered(array $filters): Builder
    {
        $query = $this->lines();

        if (isset($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['group']) && isset(self::GROUPS[$filters['group']])) {
            $query->whereIn('kind', self::GROUPS[$filters['group']]);
        }

        if (! empty($filters['hold_id'])) {
            $query->where('hold_id', (int) $filters['hold_id']);
        }

        $tz = config('app.admin_timezone');

        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['from'], $tz)->startOfDay()->utc());
        }

        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['to'], $tz)->endOfDay()->utc());
        }

        return $query;
    }

    /**
     * One page of lines, newest first, each with its record, its user and the
     * user's vault balance right after it.
     */
    public function page(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->filtered($filters)
            ->orderByDesc('occurred_at')->orderByDesc('seq')->orderByDesc('source_id')
            ->paginate($perPage);

        $rows = collect($page->items());
        $records = $this->records($rows);
        $users = User::whereIn('id', $rows->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $balances = $this->balancesFor($rows->pluck('user_id')->filter()->unique());

        $page->setCollection($rows->map(function ($row) use ($records, $users, $balances) {
            $key = $row->kind.':'.$row->source_id;
            $row->record = $records[$this->modelFor($row->kind)][$row->source_id] ?? null;
            $row->user = $users[$row->user_id] ?? null;
            $row->balance_after = $row->effect === null ? null : ($balances[$key] ?? null);
            $row->occurred_at = $row->occurred_at ? Carbon::parse($row->occurred_at, 'UTC') : null;

            return $row;
        }));

        return $page;
    }

    /**
     * Totals for the filtered lines.
     *
     * @return array{deposits: float, withdrawn: float, pulled_back: float, refunded: float, chargebacks_lost: float, vault: float, subscriptions: float, lines: int}
     */
    public function totals(array $filters): array
    {
        $byKind = $this->filtered($filters)
            ->selectRaw('kind, count(*) as n, sum(effect) as effect, sum(amount) as amount')
            ->groupBy('kind')
            ->get()
            ->keyBy('kind');

        $effect = fn (array $kinds) => round((float) $byKind->only($kinds)->sum('effect'), 2);

        return [
            'deposits' => $effect(['payment']),
            'withdrawn' => -$effect(['withdrawal']),
            'pulled_back' => $effect(self::GROUPS['reversals']),
            'refunded' => -$effect(['refund']),
            'chargebacks_lost' => -$effect(['chargeback_lost']),
            'vault' => round((float) $byKind->sum('effect'), 2),
            'subscriptions' => round((float) ($byKind['subscription']->amount ?? 0) - (float) ($byKind['subscription_refund']->amount ?? 0), 2),
            'lines' => (int) $byKind->sum('n'),
        ];
    }

    /**
     * Deposits whose vault (payment_holds.remaining_amount) does not match
     * the ledger. Empty when everything adds up.
     *
     * @return array<int, array{hold_id: int, user_id: int|null, vault: float, ledger: float}>
     */
    public function mismatches(int $limit = 20): array
    {
        $byHold = DB::query()->fromSub($this->lines(), 'l')
            ->whereNotNull('hold_id')->whereNotNull('effect')
            ->groupBy('hold_id')
            ->selectRaw('hold_id, sum(effect) as ledger');

        return DB::table('payment_holds as h')
            ->leftJoinSub($byHold, 'l', 'l.hold_id', '=', 'h.id')
            ->whereNotNull('h.remaining_amount')
            ->whereRaw('round(h.remaining_amount, 2) <> round(coalesce(l.ledger, 0), 2)')
            ->orderBy('h.id')
            ->limit($limit)
            ->get(['h.id', 'h.user_id', 'h.remaining_amount', 'l.ledger'])
            ->map(fn ($r) => [
                'hold_id' => (int) $r->id,
                'user_id' => $r->user_id ? (int) $r->user_id : null,
                'vault' => (float) $r->remaining_amount,
                'ledger' => round((float) $r->ledger, 2),
            ])
            ->all();
    }

    /**
     * Running vault balance after each line, per user, oldest first.
     *
     * @return array<string, float> "kind:source_id" => balance
     */
    private function balancesFor(Collection $userIds): array
    {
        if ($userIds->isEmpty()) {
            return [];
        }

        $lines = $this->lines()
            ->whereIn('user_id', $userIds->all())
            ->whereNotNull('effect')
            ->orderBy('occurred_at')->orderBy('seq')->orderBy('source_id')
            ->get(['kind', 'source_id', 'user_id', 'effect']);

        $running = [];
        $balances = [];

        foreach ($lines as $line) {
            $cents = ($running[$line->user_id] ?? 0) + (int) round((float) $line->effect * 100);
            $running[$line->user_id] = $cents;
            $balances[$line->kind.':'.$line->source_id] = $cents / 100;
        }

        return $balances;
    }

    /** @return array<class-string, array<int, object>> the records behind the page lines */
    private function records(Collection $rows): array
    {
        $records = [];

        foreach ($rows->groupBy(fn ($row) => $this->modelFor($row->kind)) as $model => $group) {
            $records[$model] = $model::whereIn('id', $group->pluck('source_id')->unique())->get()->keyBy('id')->all();
        }

        return $records;
    }

    private function modelFor(string $kind): string
    {
        return match (true) {
            $kind === 'payment' => Payment::class,
            $kind === 'refund' => Refund::class,
            str_starts_with($kind, 'chargeback_') => Dispute::class,
            str_starts_with($kind, 'subscription') => SubscriptionInvoice::class,
            default => Transfer::class, // withdrawal, reversal_*
        };
    }
}
