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

        return response()->json([
            'total_held' => (float) $user->paymentHolds->where('status', 'holding')
                ->sum(fn ($hold) => (float) ($hold->remaining_amount ?? $hold->amount)),
            'total_ready' => (float) $user->paymentHolds
                ->whereIn('status', ['ready_for_transfer', 'partial_transferred'])
                ->sum(fn ($hold) => (float) ($hold->remaining_amount ?? $hold->amount)),
            'total_withdrawn' => (float) $user->transfers
                ->where('status', 'completed')
                ->sum('amount'),
            'holds_count' => $user->paymentHolds->count(),
            'transfers_count' => $user->transfers->count(),
            'bank_accounts_count' => $bankAccountsCount,
        ]);
    }

    /**
     * Show a single user with all their activity.
     */
    public function show(User $user): View
    {
        $user->load(['notificationSettings']);

        // Paginated queries (10 per page)
        $paymentHolds = \App\Models\PaymentHold::where('user_id', $user->id)
            ->with(['payment', 'transfer'])
            ->latest()
            ->paginate(10, ['*'], 'holds_page')
            ->withQueryString();

        $transfers = \App\Models\Transfer::where('user_id', $user->id)
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
            'total_withdrawn' => (float) \App\Models\Transfer::where('user_id', $user->id)
                ->where('status', 'completed')
                ->sum('amount'),
        ];

        $freeTier = app(\App\Services\FreeTierService::class);
        $subscription = $user->activeSubscription ?? $user->subscriptions()->with('plan')->first();
        $freeUsage = [
            'lock' => $freeTier->quota($user, \App\Services\FreeTierService::LOCK),
            'withdraw' => $freeTier->quota($user, \App\Services\FreeTierService::WITHDRAW),
        ];

        return view('admin.users.show', compact('user', 'userAmounts', 'paymentHolds', 'transfers', 'bankAccounts', 'subscription', 'freeUsage'));
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
