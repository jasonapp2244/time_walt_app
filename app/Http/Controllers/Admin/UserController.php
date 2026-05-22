<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        $users = $query->latest()->paginate(10)->withQueryString();

        return view('admin.users.index', compact('users'));
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
            'total_held' => (float) $user->paymentHolds->where('status', 'holding')->sum('amount'),
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
        $user->load([
            'notificationSettings',
            'paymentHolds' => fn ($q) => $q->with(['payment', 'transfer'])->latest(),
            'transfers' => fn ($q) => $q->with('hold.payment')->latest(),
        ]);

        $bankAccounts = \App\Models\UserBankAccount::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();

        $userAmounts = [
            'total_held' => $user->paymentHolds->where('status', 'holding')->sum('amount'),
            'total_ready' => $user->paymentHolds
                ->whereIn('status', ['ready_for_transfer', 'partial_transferred'])
                ->sum(fn ($hold) => (float) ($hold->remaining_amount ?? $hold->amount)),
            'total_withdrawn' => $user->transfers
                ->where('status', 'completed')
                ->sum('amount'),
        ];

        return view('admin.users.show', compact('user', 'userAmounts', 'bankAccounts'));
    }
}
