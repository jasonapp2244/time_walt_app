<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /** Status => label, in the order the activity tracker lists them. */
    public const HOLD_STATUSES = [
        'holding' => 'Locked',
        'ready_for_transfer' => 'Ready',
        'partial_transferred' => 'Partly withdrawn',
        'transferred' => 'Withdrawn',
        'refunded' => 'Refunded',
        'canceled' => 'Canceled',
    ];

    public const PAYMENT_STATUSES = [
        'succeeded' => 'Succeeded',
        'pending' => 'Pending',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
    ];

    public const TRANSFER_STATUSES = [
        'completed' => 'Completed',
        'pending' => 'Pending',
        'processing' => 'Processing',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
    ];

    public const REFUND_STATUSES = [
        'succeeded' => 'Succeeded',
        'pending' => 'Pending at Stripe',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
    ];

    /**
     * List all non-admin users with search and pagination.
     *
     * Because email and full_name are encrypted, free-text search is not possible via SQL LIKE.
     * We support: exact email lookup via blind index, or lookup by numeric user ID.
     */
    public function index(Request $request): View
    {
        $query = $this->filteredQuery($request);

        $users = $query->latest()->paginate(10)->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    /**
     * Download the list, with the page's filters, as Excel or PDF.
     */
    public function export(Request $request): Response
    {
        $format = in_array($request->query('format'), ListExport::FORMATS, true) ? $request->query('format') : 'xlsx';

        return ListExport::download($format, 'Users', $this->filteredQuery($request)->with('activeSubscription.plan')->latest(), [
            'User ID', 'Name', 'Email', 'Phone', 'Status', 'Verified', 'Holds', 'Transfers',
            'Free locks used', 'Free withdrawals used', 'Subscription', 'Joined',
        ], fn (User $u) => [
            str_pad($u->id, 5, '0', STR_PAD_LEFT), $u->displayName(), $u->displayEmail(), $u->safe('phone'),
            ucfirst($u->status ?? 'active'), (bool) $u->is_verified, $u->payment_holds_count, $u->transfers_count,
            $u->free_locks_used, $u->free_withdrawals_used, $u->activeSubscription?->plan?->name ?? 'Free tier', $u->created_at,
        ], ['Status' => $request->status, 'Search' => $request->search]);
    }

    /**
     * The users list query with the page's filters applied.
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = User::where('role', 'user')
            ->withCount(['paymentHolds', 'transfers']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            if (is_numeric($search)) {
                $query->where('id', (int) $search);
            } else {
                $query->where('email_index', User::blindIndex(strtolower($search)));
            }
        }

        return $query;
    }

    /**
     * Return live user status breakdown as JSON for AJAX polling.
     * Tracks total, verified, and per-status counts so any status
     * change (e.g. pending → active after OTP verification) is detected.
     */
    public function stats(): JsonResponse
    {
        $base = User::where('role', 'user')->where('status', '!=', 'deleted');

        return response()->json([
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'inactive' => (clone $base)->where('status', 'inactive')->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'verified' => (clone $base)->where('is_verified', true)->count(),
        ]);
    }

    /**
     * Return live stats for a single user as JSON for AJAX polling.
     */
    public function userStats(User $user): JsonResponse
    {
        $user->load([
            'paymentHolds',
            'transfers',
        ]);

        $bankAccountsCount = \App\Models\UserBankAccount::where('user_id', $user->id)->count();

        $held = (float) $user->paymentHolds->where('status', 'holding')
            ->sum(fn ($hold) => (float) ($hold->remaining_amount ?? $hold->amount));
        $ready = (float) $user->paymentHolds
            ->whereIn('status', ['ready_for_transfer', 'partial_transferred'])
            ->sum(fn ($hold) => (float) ($hold->remaining_amount ?? $hold->amount));
        $withdrawn = (float) $user->transfers
            ->where('status', 'completed')
            ->sum(fn ($transfer) => (float) $transfer->amount - (float) $transfer->reversed_amount);

        return response()->json([
            'total_held' => $held,
            'total_ready' => $ready,
            'total_withdrawn' => $withdrawn,
            'total_amount' => round($held + $ready + $withdrawn, 2),
            'holds_count' => $user->paymentHolds->count(),
            'transfers_count' => $user->transfers->count(),
            'bank_accounts_count' => $bankAccountsCount,
        ]);
    }

    /**
     * Show a single user with all their activity.
     */
    public function show(Request $request, User $user): View
    {
        $user->load(['notificationSettings']);

        // A click on a count in the activity tracker filters the table below it.
        $holdStatus = array_key_exists((string) $request->query('hold_status'), self::HOLD_STATUSES) ? (string) $request->query('hold_status') : null;
        $transferStatus = array_key_exists((string) $request->query('transfer_status'), self::TRANSFER_STATUSES) ? (string) $request->query('transfer_status') : null;

        // Paginated queries (10 per page)
        $paymentHolds = \App\Models\PaymentHold::where('user_id', $user->id)
            ->when($holdStatus, fn ($q) => $q->where('status', $holdStatus))
            ->with(['payment', 'transfer'])
            ->latest()
            ->paginate(10, ['*'], 'holds_page')
            ->withQueryString();

        $transfers = \App\Models\Transfer::where('user_id', $user->id)
            ->when($transferStatus, fn ($q) => $q->where('status', $transferStatus))
            ->with(['hold.payment', 'bankAccount'])
            ->latest()
            ->paginate(10, ['*'], 'transfers_page')
            ->withQueryString();

        $bankAccounts = \App\Models\UserBankAccount::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->paginate(10, ['*'], 'banks_page')
            ->withQueryString();

        // Amount summaries (from ALL records, not paginated)
        $userAmounts = [
            'total_held' => (float) \App\Models\PaymentHold::where('user_id', $user->id)
                ->where('status', 'holding')
                ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(remaining_amount, amount)')),
            'total_ready' => (float) \App\Models\PaymentHold::where('user_id', $user->id)
                ->whereIn('status', ['ready_for_transfer', 'partial_transferred'])
                ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(remaining_amount, amount)')),
            // Net of any part pulled back from Connect for a refund.
            'total_withdrawn' => (float) \App\Models\Transfer::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum(\Illuminate\Support\Facades\DB::raw('amount - reversed_amount')),
            'holds_count' => \App\Models\PaymentHold::where('user_id', $user->id)->count(),
            'transfers_count' => \App\Models\Transfer::where('user_id', $user->id)->count(),
        ];

        // Hold + transfer: what is still in the vault (locked + ready) plus what was withdrawn.
        $userAmounts['total_amount'] = round($userAmounts['total_held'] + $userAmounts['total_ready'] + $userAmounts['total_withdrawn'], 2);

        $activity = $this->activity($user);

        $freeTier = app(\App\Services\FreeTierService::class);
        $subscription = $user->activeSubscription ?? $user->subscriptions()->with('plan')->first();
        $freeUsage = [
            'lock' => $freeTier->quota($user, \App\Services\FreeTierService::LOCK),
            'withdraw' => $freeTier->quota($user, \App\Services\FreeTierService::WITHDRAW),
        ];

        return view('admin.users.show', compact('user', 'userAmounts', 'paymentHolds', 'transfers', 'bankAccounts', 'subscription', 'freeUsage', 'activity', 'holdStatus', 'transferStatus'));
    }

    /**
     * Count and amount of the user's deposits, card payments, withdrawals and
     * refunds by status, plus where their Stripe account stands. One grouped
     * query per table, read-only.
     *
     * @return array<string, mixed>
     */
    private function activity(User $user): array
    {
        $grouped = function (string $model, array $statuses) use ($user): array {
            $rows = $model::where('user_id', $user->id)
                ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $byStatus = [];

            foreach ($statuses as $status => $label) {
                $byStatus[$status] = [
                    'label' => $label,
                    'count' => (int) ($rows[$status]->total ?? 0),
                    'amount' => (float) ($rows[$status]->amount ?? 0),
                ];
            }

            return ['total' => (int) $rows->sum('total'), 'statuses' => $byStatus];
        };

        $transfers = $grouped(\App\Models\Transfer::class, self::TRANSFER_STATUSES);
        $reversed = \App\Models\Transfer::where('user_id', $user->id)->where('reversed_amount', '>', 0);
        $transfers['reversed'] = ['count' => (clone $reversed)->count(), 'amount' => (float) $reversed->sum('reversed_amount')];

        $refunds = $grouped(\App\Models\Refund::class, self::REFUND_STATUSES);
        $refunds['attention'] = \App\Models\Refund::where('user_id', $user->id)->where('needs_attention', true)->count();

        $holds = $grouped(\App\Models\PaymentHold::class, self::HOLD_STATUSES);
        $holds['abandoned'] = \App\Models\PaymentHold::where('user_id', $user->id)->whereNotNull('abandoned_at')->count();

        $connect = \App\Models\StripeConnectAccount::where('user_id', $user->id)->latest('id')->first();

        return [
            'holds' => $holds,
            'payments' => $grouped(\App\Models\Payment::class, self::PAYMENT_STATUSES),
            'transfers' => $transfers,
            'refunds' => $refunds,
            'disputes' => [
                'open' => \App\Models\Dispute::where('user_id', $user->id)->where('outcome', 'open')->count(),
                'lost' => \App\Models\Dispute::where('user_id', $user->id)->where('outcome', 'lost')->count(),
            ],
            'stripe' => [
                'connected' => $connect !== null,
                'status' => $connect?->status,
                'payouts_enabled' => (bool) $connect?->payouts_enabled,
                'verified_at' => $connect?->verified_at,
            ],
        ];
    }

    /**
     * Give a user their free locks and withdrawals back (support tool).
     */
    public function resetFreeUsage(User $user): RedirectResponse
    {
        $user->forceFill(['free_locks_used' => 0, 'free_withdrawals_used' => 0])->save();

        return back()->with('success', 'Free locks and withdrawals reset for this user.');
    }
}
